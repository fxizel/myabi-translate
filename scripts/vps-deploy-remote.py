"""Private, code-only myABI deployment transaction; sent over verified SSH."""
import contextlib
import gzip
import hashlib
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import time
import urllib.parse
import urllib.request

try:
    import fcntl
except ImportError:  # Allows fixture imports on a Windows client.
    fcntl = None

ALLOWED_TIMERS = {"myabi-docker-work.timer", "myabi-docker-notifications.timer"}


class DeployError(Exception):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def require(condition, code):
    if not condition:
        raise DeployError(code)


def safe_path(path, root=None, existing=False):
    path = pathlib.Path(path)
    require(path.is_absolute() and path != pathlib.Path("/") and ".." not in path.parts, "unsafe_path")
    for parent in [path, *path.parents]:
        require(not parent.is_symlink(), "symlink_refused")
    if root is not None:
        require(path.is_relative_to(root) and path != root, "path_outside_root")
    require(not existing or path.exists(), "required_path_missing")
    return path


def atomic_bytes(path, value, mode=0o600, ownership=None):
    safe_path(path)
    temporary = path.with_name(path.name + ".partial-" + str(os.getpid()))
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), mode)
    try:
        if ownership is not None:
            os.fchown(fd, *ownership)
        with os.fdopen(fd, "wb") as target:
            target.write(value)
            target.flush()
            os.fsync(target.fileno())
        os.replace(temporary, path)
        directory_fd = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
    finally:
        if temporary.exists():
            temporary.unlink()


def digest(path):
    safe_path(path, existing=True)
    result = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            result.update(chunk)
    return result.hexdigest()


def private_log(path, layout):
    safe_path(path, layout["root"])
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_APPEND | getattr(os, "O_NOFOLLOW", 0), 0o600)
    return os.fdopen(fd, "ab")


def run(command, layout, lease=None, check=True, timeout=180):
    if lease is not None and "compose_files" in lease and (command[:2] == ["docker", "compose"] or command[:2] in (["systemctl", "start"], ["systemctl", "stop"])):
        verify_compose_files(layout, lease)
    result = subprocess.run(command, cwd=layout["docker"], capture_output=True, timeout=timeout)
    if lease is not None:
        log = layout["stage"] / "commands.private.log"
        with private_log(log, layout) as target:
            target.write(result.stdout + result.stderr)
    if check:
        require(result.returncode == 0, "command_failed")
    return result


def compose(layout, *args):
    return ["docker", "compose", "--ansi", "never", "--project-name", "myabi-translate", "--env-file", "compose.env", *args]


def compose_hashes(layout):
    paths = sorted({*layout["docker"].glob("compose*.yml"), *layout["docker"].glob("compose*.yaml")})
    return {path.name: digest(safe_path(path, layout["root"], True)) for path in paths}


def verify_compose_files(layout, lease):
    require("compose_files" in lease, "compose_snapshot_missing")
    require(compose_hashes(layout) == lease["compose_files"], "compose_configuration_changed")


def text_run(command, layout, lease=None, **kwargs):
    return run(command, layout, lease, **kwargs).stdout.decode("utf-8", "strict").strip()


def layout_for(payload):
    cfg, release = payload.get("config", {}), payload.get("release", {})
    require(cfg.get("project", "myabi-translate") == "myabi-translate", "project_refused")
    root = safe_path(cfg.get("remote_root", ""), existing=True)
    require(root.is_dir(), "root_not_directory")
    docker = safe_path(root / "deploy/docker", root, True)
    for name in ["compose.env", "compose.yml", "secrets/app.env", "data/storage/app/private/operations.lock"]:
        require(safe_path(docker / name, root, True).is_file(), "required_file_missing")
    timers = cfg.get("timer_names", sorted(ALLOWED_TIMERS))
    require(isinstance(timers, list) and len(timers) == len(set(timers)) and set(timers) <= ALLOWED_TIMERS, "timer_refused")
    require(isinstance(cfg.get("operations_timeout_seconds", 2100), int) and 1 <= cfg.get("operations_timeout_seconds", 2100) <= 7200, "timeout_refused")
    require(isinstance(cfg.get("min_free_bytes", 3 * 1024**3), int) and cfg.get("min_free_bytes", 3 * 1024**3) >= 0, "free_space_threshold_refused")
    url = urllib.parse.urlsplit(cfg.get("site_url", ""))
    require(url.scheme == "https" and url.hostname and not url.username and not url.password and url.path in ("", "/") and not url.query and not url.fragment, "site_url_refused")
    run_id = release.get("run_id", "")
    require(not run_id or re.fullmatch(r"[0-9a-f]{32}", run_id), "run_id_refused")
    operations = safe_path(root / "operations", root)
    return {"root": root, "docker": docker, "operations": operations,
            "stage": safe_path(operations / ("deploy-" + run_id), root),
            "active": operations / "active-deployment.json", "config": cfg, "release": release,
            "private": docker / "data/storage/app/private", "down": docker / "data/storage/framework/down",
            "operation_lock": docker / "data/storage/app/private/operations.lock", "timers": timers,
            "url": urllib.parse.urlunsplit(url).rstrip("/")}


def validate_release(release):
    require(re.fullmatch(r"[0-9a-f]{32}", release.get("run_id", "")), "run_id_refused")
    require(re.fullmatch(r"[0-9a-f]{40}", release.get("revision", "")), "revision_refused")
    require(release.get("tag") == "sha-" + release["revision"][:12], "tag_refused")
    require(re.fullmatch(r"[0-9a-f]{40}", release.get("previous_revision", "")), "previous_revision_refused")
    require(re.fullmatch(r"[A-Za-z0-9._-]+", release.get("previous_tag", "")), "previous_tag_refused")
    images = release.get("images", [])
    require(len(images) == 2 and {item.get("service") for item in images} == {"app", "myabi-web"}, "images_refused")
    for image in images:
        require(re.fullmatch(r"sha256:[0-9a-f]{64}", image.get("id", "")), "image_id_refused")
        require(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9./_:@-]*", image.get("ref", "")) and image["ref"].endswith(":" + release["tag"]), "image_ref_refused")


def selected_env(path, name):
    safe_path(path, existing=True)
    matches = re.findall(rb"(?m)^[ \t]*" + name.encode() + rb"[ \t]*=[ \t]*([^\r\n]*)", path.read_bytes())
    require(len(matches) == 1, "environment_key_invalid")
    return matches[0].decode().strip().strip("\"'")


def changed_env(original, name, value, optional=False):
    pattern = rb"(?m)^([ \t]*" + name.encode() + rb"[ \t]*=[ \t]*)[^\r\n]*(\r?)$"
    result, count = re.subn(pattern, lambda match: match[1] + value.encode("ascii") + match[2], original)
    require(count == 1 or optional and count == 0, "environment_key_invalid")
    return result


def containers(layout, lease=None):
    result = {}
    for service in ["app", "myabi-web", "db"]:
        ids = text_run(compose(layout, "ps", "-q", service), layout, lease).splitlines()
        require(len(ids) == 1, "service_not_running")
        template = '{{.Id}}|{{.Image}}|{{.Config.Image}}|{{.State.Status}}|{{with index .State "Health"}}{{.Status}}{{else}}none{{end}}'
        values = text_run(["docker", "inspect", "--format", template, ids[0]], layout, lease).split("|")
        require(len(values) == 5, "container_metadata_invalid")
        result[service] = dict(zip(["container_id", "image_id", "ref", "status", "health"], values))
    return result


def manifest(layout, lease=None):
    value = text_run(compose(layout, "exec", "-T", "app", "php", "-r", "echo file_get_contents('/var/www/html/RELEASE.json');"), layout, lease)
    result = json.loads(value)
    require(re.fullmatch(r"[0-9a-f]{40}", result.get("commit", "")), "manifest_invalid")
    return result


def unrelated(layout, lease=None):
    template = '{{.ID}}|{{.Names}}|{{.Label "com.docker.compose.project"}}'
    result = text_run(["docker", "ps", "--no-trunc", "--format", template], layout, lease)
    return sorted(line for line in result.splitlines() if not line.endswith("|myabi-translate"))


def status(layout):
    services = containers(layout)
    current = manifest(layout)
    lease = read_lease(layout, required=False)
    return {"revision": current["commit"], "tag": selected_env(layout["docker"] / "compose.env", "RELEASE_TAG"),
            "manifest": current, "images": services, "maintenance": safe_path(layout["down"], layout["root"]).exists(),
            "healthy": all(item["status"] == "running" and item["health"] in ("healthy", "none") for item in services.values()),
            "lease": {"run_id": lease.get("run_id"), "phase": lease.get("phase")} if lease else None,
            "leaseowner": lease.get("run_id") if lease else None, "available_bytes": shutil.disk_usage(layout["root"]).free}


def read_lease(layout, required=True):
    path = safe_path(layout["active"], layout["root"])
    if not path.exists():
        require(not required, "lease_missing")
        return None
    result = json.loads(path.read_text())
    if required:
        require(result.get("run_id") == layout["release"].get("run_id"), "lease_owned_by_other_run")
    return result


def save_lease(layout, lease):
    atomic_bytes(layout["active"], json.dumps(lease, sort_keys=True).encode())


@contextlib.contextmanager
def deployment_guard(layout, create=False):
    require(fcntl is not None, "remote_platform_unsupported")
    operations = safe_path(layout["operations"], layout["root"])
    if create and not operations.exists():
        operations.mkdir(mode=0o700)
    require(operations.is_dir(), "operations_directory_missing")
    require(operations.stat().st_mode & 0o077 == 0, "operations_directory_not_private")
    path = safe_path(operations / "deployment.lock", layout["root"])
    fd = os.open(path, os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise DeployError("deployment_busy") from None
        yield
    finally:
        os.close(fd)


@contextlib.contextmanager
def operation_guard(layout, lease):
    path = safe_path(layout["operation_lock"], layout["root"], True)
    fd = os.open(path, os.O_RDWR | getattr(os, "O_NOFOLLOW", 0))
    try:
        current = os.fstat(fd)
        require([current.st_dev, current.st_ino] == lease["operation_lock_identity"], "operation_lock_changed")
        deadline = time.monotonic() + layout["config"].get("operations_timeout_seconds", 2100)
        while True:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                require(time.monotonic() < deadline, "operations_timeout")
                time.sleep(1)
        yield
    finally:
        os.close(fd)


def timer_active(timer, layout, lease=None):
    return text_run(["systemctl", "is-active", timer], layout, lease, check=False) == "active"


def restore_timers(layout, lease):
    for timer in lease.get("stopped_timers", []):
        if lease["timers"][timer]:
            run(["systemctl", "start", timer], layout, lease)


def private_hashes(layout):
    result = {}
    for directory, directories, files in os.walk(layout["private"], followlinks=False):
        for name in [*directories, *files]:
            safe_path(pathlib.Path(directory) / name, layout["root"])
        for name in files:
            if name != "operations.lock":
                path = pathlib.Path(directory) / name
                result[str(path.relative_to(layout["private"]))] = digest(path)
    return result


STATE_PHP = """try { require 'vendor/autoload.php'; $a=require 'bootstrap/app.php';
$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$db=Illuminate\\Support\\Facades\\DB::class; $schema=Illuminate\\Support\\Facades\\Schema::class;
$v=['counts'=>[], 'pending_migrations'=>count(array_diff(array_keys($a->make('migrator')->getMigrationFiles(database_path('migrations'))),$a->make('migrator')->getRepository()->getRan()))];
foreach(['users','organizations','terms','proposals','revisions','import_batches','import_rows','publications','audit_events','myabi_versions'] as $t){$v['counts'][$t]=$db::table($t)->count();}
$v['catalogue_generation']=$db::table('catalogue_state')->where('id',1)->value('generation');
$v['unfinished']=$db::table('import_batches')->where('status','applying')->count()+$db::table('publications')->where('status','building')->count()+$db::table('bulk_operations')->where('status','running')->count();
$v['projection']=$schema::hasTable('browser_extension_search_state')?(array)$db::table('browser_extension_search_state')->where('id',1)->first():null;
if($v['projection']) {foreach(['values','tokens','dirty'] as $t){$v['projection'][$t.'_count']=$db::table('browser_extension_search_'.$t)->count();}}
echo json_encode($v, JSON_THROW_ON_ERROR); } catch(Throwable $e) {fwrite(STDERR,'metadata_check_failed'); exit(1);} """


def state(layout, lease):
    return json.loads(text_run(compose(layout, "run", "--rm", "--no-deps", "-T", "cli", "php", "-r", STATE_PHP), layout, lease))


def verify_preservation(layout, lease):
    require(state(layout, lease) == lease["state_before"], "catalogue_state_changed")
    for name, checksum in lease["private_hashes"].items():
        require(digest(safe_path(layout["private"] / name, layout["root"], True)) == checksum, "private_file_changed")
    require(containers(layout, lease)["db"] == lease["containers"]["db"], "database_container_changed")
    require(unrelated(layout, lease) == lease["unrelated"], "unrelated_containers_changed")


def owned_down(layout, lease):
    require(lease.get("owned_maintenance") and layout["down"].exists() and digest(layout["down"]) == lease.get("maintenance_hash"), "maintenance_ownership_lost")


def close_owned(layout, lease):
    if lease.get("owned_maintenance") and not layout["down"].exists():
        atomic_bytes(layout["down"], (layout["stage"] / "maintenance.before").read_bytes(), 0o660, tuple(lease["maintenance_owner"]))


def open_owned(layout, lease):
    if layout["down"].exists():
        owned_down(layout, lease)
        run(compose(layout, "run", "--rm", "--no-deps", "-T", "cli", "php", "artisan", "up", "--quiet"), layout, lease)
    require(not layout["down"].exists(), "maintenance_remains")


def reload_nginx(layout, lease):
    run(compose(layout, "exec", "-T", "myabi-web", "nginx", "-t"), layout, lease, timeout=30)
    # Configuration validity does not mean that the newly created master has
    # written its PID yet. Only signal it after the PID identifies a live process.
    probe = ("test -s /var/run/nginx.pid || exit 1; "
             "pid=$(cat /var/run/nginx.pid) || exit 1; "
             "case \"$pid\" in ''|*[!0-9]*) exit 1 ;; esac; "
             "[ \"$pid\" -gt 0 ] || exit 1; kill -0 \"$pid\"")
    deadline = time.monotonic() + 60
    while True:
        remaining = deadline - time.monotonic()
        require(remaining > 0, "nginx_timeout")
        try:
            ready = run(compose(layout, "exec", "-T", "myabi-web", "sh", "-c", probe),
                        layout, lease, check=False, timeout=min(10, remaining))
            if ready.returncode == 0:
                break
        except subprocess.TimeoutExpired:
            pass
        remaining = deadline - time.monotonic()
        require(remaining > 0, "nginx_timeout")
        time.sleep(min(1, remaining))
    run(compose(layout, "exec", "-T", "myabi-web", "nginx", "-s", "reload"), layout, lease, timeout=30)


def runtime_checks(layout, lease, opened=False):
    result = run(compose(layout, "run", "--rm", "--no-deps", "-T", "cli", "php", "scripts/operations.php", "health"), layout, lease)
    require(b"Database, schema and private runtime directories are healthy." in result.stdout, "cli_health_failed")
    audited = run(compose(layout, "run", "--rm", "--no-deps", "-T", "cli", "php", "artisan", "security:check-audit", "--no-interaction"), layout, lease)
    require(b"Audit SELECT/INSERT succeed; UPDATE/DELETE are rejected; no broad destructive grants were detected." in audited.stdout, "audit_check_failed")
    run(compose(layout, "exec", "-T", "app", "php", "-r", "$s=@fsockopen('127.0.0.1',9000);exit($s?0:1);"), layout, lease)
    run(compose(layout, "exec", "-T", "myabi-web", "nginx", "-t"), layout, lease)
    if opened:
        for route in ["/up", "/login"]:
            try:
                with urllib.request.urlopen(layout["url"] + route, timeout=20) as response:
                    require(response.status == 200 and response.url == layout["url"] + route, "https_check_failed")
            except Exception:
                raise DeployError("https_check_failed") from None


def image_status(layout):
    validate_release(layout["release"])
    missing = []
    for image in layout["release"]["images"]:
        if run(["docker", "image", "inspect", image["id"]], layout, check=False).returncode:
            missing.append(image["ref"])
    return {"missing_refs": missing}


def stage(layout):
    validate_release(layout["release"])
    require(read_lease(layout, required=False) is None, "deployment_lease_exists")
    baseline = status(layout)
    release = layout["release"]
    require(baseline["revision"] == release["previous_revision"] and baseline["tag"] == release["previous_tag"], "baseline_changed")
    require(baseline["healthy"] and not baseline["maintenance"], "baseline_unavailable")
    require(baseline["available_bytes"] >= layout["config"].get("min_free_bytes", 3 * 1024**3), "insufficient_free_space")
    require(not layout["stage"].exists(), "stage_exists")
    layout["stage"].mkdir(mode=0o700)
    lease = {"run_id": release["run_id"], "release": release, "phase": "staged", "mutated": False,
             "owned_maintenance": False, "stopped_timers": [], "containers": baseline["images"],
             "unrelated": unrelated(layout), "timers": {timer: timer_active(timer, layout) for timer in layout["timers"]},
             "compose_files": compose_hashes(layout)}
    lock_stat = layout["operation_lock"].stat()
    lease["operation_lock_identity"] = [lock_stat.st_dev, lock_stat.st_ino]
    for label, path in [("compose", layout["docker"] / "compose.env"), ("app", layout["docker"] / "secrets/app.env")]:
        info = path.stat()
        lease[label + "_owner"] = [info.st_uid, info.st_gid]
        lease[label + "_mode"] = info.st_mode & 0o777
        atomic_bytes(layout["stage"] / (label + ".env.before"), path.read_bytes())
        lease[label + "_before_hash"] = digest(path)
    save_lease(layout, lease)
    return {"stage_dir": str(layout["stage"]), "phase": lease["phase"]}


def load(layout, lease, params):
    require(lease["phase"] == "staged", "phase_refused")
    for image in layout["release"]["images"]:
        existing = text_run(["docker", "image", "inspect", "--format", "{{.Id}}", image["ref"]], layout, lease, check=False)
        require(not existing or existing == image["id"], "immutable_image_tag_conflict")
    if params.get("sha256") is not None:
        require(re.fullmatch(r"[0-9a-f]{64}", params["sha256"]), "checksum_refused")
        archive = safe_path(layout["stage"] / "images.tar.gz", layout["root"], True)
        require(digest(archive) == params["sha256"], "image_archive_corrupt")
        with gzip.open(archive, "rb") as source, private_log(layout["stage"] / "load.private.log", layout) as log:
            process = subprocess.Popen(["docker", "load"], cwd=layout["docker"], stdin=subprocess.PIPE, stdout=log, stderr=log)
            try:
                shutil.copyfileobj(source, process.stdin, 1024 * 1024)
                process.stdin.close()
                require(process.wait() == 0, "image_load_failed")
            finally:
                if process.poll() is None:
                    process.stdin.close()
                    process.wait()
        archive.unlink()
    for image in layout["release"]["images"]:
        require(run(["docker", "image", "inspect", image["id"]], layout, lease, check=False).returncode == 0, "image_missing")
        existing = text_run(["docker", "image", "inspect", "--format", "{{.Id}}", image["ref"]], layout, lease, check=False)
        require(not existing or existing == image["id"], "immutable_image_tag_conflict")
        run(["docker", "tag", image["id"], image["ref"]], layout, lease)
    app = next(image for image in layout["release"]["images"] if image["service"] == "app")
    observed = json.loads(text_run(["docker", "run", "--rm", "--network", "none", "--read-only", "--cap-drop", "ALL", "--entrypoint", "php", app["ref"], "-r", "echo file_get_contents('/var/www/html/RELEASE.json');"], layout, lease))
    require(observed.get("commit") == layout["release"]["revision"] and observed.get("version") == layout["release"]["tag"], "candidate_manifest_mismatch")
    lease["phase"] = "loaded"
    save_lease(layout, lease)
    return {"phase": "loaded"}


def freeze(layout, lease):
    require(lease["phase"] == "loaded" and not layout["down"].exists(), "preexisting_maintenance")
    verify_compose_files(layout, lease)
    require(manifest(layout, lease)["commit"] == layout["release"]["previous_revision"], "baseline_changed")
    for label, path in [("compose", layout["docker"] / "compose.env"), ("app", layout["docker"] / "secrets/app.env")]:
        require(digest(path) == lease[label + "_before_hash"], "private_configuration_changed")
    for timer, active in lease["timers"].items():
        if active:
            lease["stopped_timers"].append(timer)
            save_lease(layout, lease)
            run(["systemctl", "stop", timer], layout, lease)
    deadline = time.monotonic() + layout["config"].get("operations_timeout_seconds", 2100)
    while True:
        services_busy = any(text_run(["systemctl", "is-active", timer.replace(".timer", ".service")], layout, lease, check=False)
                            in {"active", "activating", "deactivating", "reloading"} for timer in lease["timers"])
        operations = text_run(["docker", "ps", "--filter", "label=com.docker.compose.project=myabi-translate", "--format", '{{.Label "com.docker.compose.service"}}'], layout, lease).splitlines()
        if not services_busy and not set(operations) & {"cli", "backup", "migrate", "provision"}:
            break
        require(time.monotonic() < deadline, "operations_timeout")
        time.sleep(1)
    require(not layout["down"].exists(), "preexisting_maintenance")
    lease["owned_maintenance"] = True
    lease["phase"] = "closing"
    save_lease(layout, lease)
    run(compose(layout, "run", "--rm", "--no-deps", "-T", "cli", "php", "artisan", "down", "--retry=60", "--quiet"), layout, lease)
    info = safe_path(layout["down"], layout["root"], True).stat()
    lease["maintenance_hash"] = digest(layout["down"])
    lease["maintenance_owner"] = [info.st_uid, info.st_gid]
    atomic_bytes(layout["stage"] / "maintenance.before", layout["down"].read_bytes())
    save_lease(layout, lease)
    with operation_guard(layout, lease):
        lease["state_before"] = state(layout, lease)
        require(lease["state_before"]["unfinished"] == 0 and lease["state_before"]["pending_migrations"] == 0, "unfinished_business_writes")
        projection = lease["state_before"]["projection"]
        require(projection is None or projection.get("ready") in (True, 1), "projection_not_ready")
        lease["private_hashes"] = private_hashes(layout)
    lease["phase"] = "frozen"
    save_lease(layout, lease)
    return {"phase": "frozen", "private_files": len(lease["private_hashes"])}


def backup(layout, lease):
    require(lease["phase"] == "frozen", "phase_refused")
    verify_compose_files(layout, lease)
    owned_down(layout, lease)
    checksum, size = hashlib.sha256(), 0
    with private_log(layout["stage"] / "backup.private.log", layout) as log:
        process = subprocess.Popen(compose(layout, "run", "--rm", "--no-deps", "-T", "backup"), cwd=layout["docker"], stdout=subprocess.PIPE, stderr=log)
        try:
            for chunk in iter(lambda: process.stdout.read(1024 * 1024), b""):
                checksum.update(chunk)
                size += len(chunk)
                sys.stdout.buffer.write(chunk)
            sys.stdout.buffer.flush()
            require(process.wait() == 0, "backup_failed")
        finally:
            process.stdout.close()
    lease["backup_stream"] = {"sha256": checksum.hexdigest(), "bytes": size}
    lease["phase"] = "backed_up"
    save_lease(layout, lease)
    return None


def switch(layout, lease):
    require(lease["phase"] == "verified_backup", "backup_not_verified")
    verify_compose_files(layout, lease)
    owned_down(layout, lease)
    with operation_guard(layout, lease):
        verify_preservation(layout, lease)
        for label, path in [("compose", layout["docker"] / "compose.env"), ("app", layout["docker"] / "secrets/app.env")]:
            require(digest(path) == lease[label + "_before_hash"], "private_configuration_changed")
        lease["mutated"] = True
        lease["phase"] = "switching"
        save_lease(layout, lease)
        run(compose(layout, "stop", "--timeout", "130", "app", "myabi-web"), layout, lease, timeout=180)
        compose_bytes = changed_env((layout["stage"] / "compose.env.before").read_bytes(), "APP_REVISION", layout["release"]["revision"])
        compose_bytes = changed_env(compose_bytes, "RELEASE_TAG", layout["release"]["tag"])
        app_bytes = changed_env((layout["stage"] / "app.env.before").read_bytes(), "RELEASE_VERSION", layout["release"]["tag"], optional=True)
        for label, value, path in [("compose", compose_bytes, layout["docker"] / "compose.env"), ("app", app_bytes, layout["docker"] / "secrets/app.env")]:
            atomic_bytes(path, value, lease[label + "_mode"], tuple(lease[label + "_owner"]))
            lease[label + "_expected_hash"] = digest(path)
        save_lease(layout, lease)
        run(compose(layout, "config", "--quiet"), layout, lease)
        metadata = state(layout, lease)
        require(metadata["pending_migrations"] == 0, "pending_migrations")
        run(compose(layout, "run", "--rm", "--no-deps", "-T", "cli", "php", "artisan", "view:clear", "--quiet"), layout, lease)
        run(compose(layout, "up", "-d", "--no-deps", "--force-recreate", "app", "myabi-web"), layout, lease)
        run(compose(layout, "exec", "-T", "app", "php", "artisan", "config:cache", "--quiet"), layout, lease)
        run(compose(layout, "kill", "-s", "USR2", "app"), layout, lease)
        deadline = time.monotonic() + 60
        while run(compose(layout, "exec", "-T", "app", "php", "-r", "$s=@fsockopen('127.0.0.1',9000);exit($s?0:1);"), layout, lease, check=False).returncode:
            require(time.monotonic() < deadline, "fpm_timeout")
            time.sleep(1)
        reload_nginx(layout, lease)
        runtime_checks(layout, lease)
        verify_preservation(layout, lease)
        require(manifest(layout, lease)["commit"] == layout["release"]["revision"], "manifest_mismatch")
        running = containers(layout, lease)
        require(all(running[item["service"]]["image_id"] == item["id"] for item in layout["release"]["images"]), "running_image_mismatch")
        lease["preservation_verified"] = True
        save_lease(layout, lease)
        open_owned(layout, lease)
        runtime_checks(layout, lease, opened=True)
    restore_timers(layout, lease)
    lease["phase"] = "switched"
    save_lease(layout, lease)
    return {"phase": "switched", "preservation_verified": True}


def recover(layout, lease):
    verify_compose_files(layout, lease)
    if lease.get("owned_maintenance") and not lease.get("maintenance_hash"):
        # The closing intent was durable, but receipt ownership was not. Preserve
        # an existing marker; if no marker exists, close this previously-open
        # installation conservatively. Do not reopen, restart timers or clear lease.
        if not layout["down"].exists():
            marker = {"except": [], "redirect": None, "retry": 60, "refresh": None,
                      "secret": None, "status": 503, "template": None}
            info = layout["operation_lock"].stat()
            atomic_bytes(layout["down"], json.dumps(marker).encode(), 0o660, (info.st_uid, info.st_gid))
        lease["phase"] = "maintenance_recovery_required"
        save_lease(layout, lease)
        raise DeployError("maintenance_receipt_missing")
    if not lease["mutated"] and not lease.get("owned_maintenance"):
        restore_timers(layout, lease)
        receipt = {"phase": "recovered", "run_id": lease["run_id"], "revision": layout["release"]["revision"],
                   "active_revision": layout["release"]["previous_revision"], "maintenance": layout["down"].exists(),
                   "compose_files": lease["compose_files"]}
        atomic_bytes(layout["stage"] / "receipt.json", json.dumps(receipt).encode())
        layout["active"].unlink()
        return {"phase": "recovered", "maintenance": layout["down"].exists()}
    with operation_guard(layout, lease):
        if lease["mutated"]:
            close_owned(layout, lease)
            expected_compose = changed_env((layout["stage"] / "compose.env.before").read_bytes(), "APP_REVISION", layout["release"]["revision"])
            expected_compose = changed_env(expected_compose, "RELEASE_TAG", layout["release"]["tag"])
            expected_app = changed_env((layout["stage"] / "app.env.before").read_bytes(), "RELEASE_VERSION", layout["release"]["tag"], optional=True)
            for label, expected, path in [("compose", expected_compose, layout["docker"] / "compose.env"), ("app", expected_app, layout["docker"] / "secrets/app.env")]:
                require(digest(path) in {lease[label + "_before_hash"], hashlib.sha256(expected).hexdigest()}, "configuration_changed_recovery_required")
            run(compose(layout, "stop", "--timeout", "130", "app", "myabi-web"), layout, lease)
            for service in ["app", "myabi-web"]:
                old = lease["containers"][service]
                run(["docker", "image", "inspect", old["image_id"]], layout, lease)
                run(["docker", "tag", old["image_id"], old["ref"]], layout, lease)
            for label, path in [("compose", layout["docker"] / "compose.env"), ("app", layout["docker"] / "secrets/app.env")]:
                atomic_bytes(path, (layout["stage"] / (label + ".env.before")).read_bytes(), lease[label + "_mode"], tuple(lease[label + "_owner"]))
            run(compose(layout, "config", "--quiet"), layout, lease)
            run(compose(layout, "up", "-d", "--no-deps", "--force-recreate", "app", "myabi-web"), layout, lease)
            run(compose(layout, "exec", "-T", "app", "php", "artisan", "config:cache", "--quiet"), layout, lease)
            run(compose(layout, "kill", "-s", "USR2", "app"), layout, lease)
            deadline = time.monotonic() + 60
            while run(compose(layout, "exec", "-T", "app", "php", "-r", "$s=@fsockopen('127.0.0.1',9000);exit($s?0:1);"), layout, lease, check=False).returncode:
                require(time.monotonic() < deadline, "fpm_timeout")
                time.sleep(1)
            reload_nginx(layout, lease)
            runtime_checks(layout, lease)
            require(manifest(layout, lease)["commit"] == layout["release"]["previous_revision"], "rollback_manifest_mismatch")
        if lease.get("owned_maintenance") and lease.get("maintenance_hash"):
            open_owned(layout, lease)
            runtime_checks(layout, lease, opened=True)
    restore_timers(layout, lease)
    lease["phase"] = "recovered"
    receipt = {"phase": "recovered", "run_id": lease["run_id"], "revision": layout["release"]["revision"],
               "active_revision": layout["release"]["previous_revision"], "maintenance": layout["down"].exists(),
               "compose_files": lease["compose_files"]}
    atomic_bytes(layout["stage"] / "receipt.json", json.dumps(receipt).encode())
    layout["active"].unlink()
    return {"phase": "recovered", "maintenance": layout["down"].exists()}


def finalize(layout, lease):
    require(lease["phase"] == "switched" and lease.get("preservation_verified"), "phase_refused")
    verify_compose_files(layout, lease)
    require(manifest(layout, lease)["commit"] == layout["release"]["revision"], "manifest_mismatch")
    for image in layout["release"]["images"]:
        require(containers(layout, lease)[image["service"]]["image_id"] == image["id"], "running_image_mismatch")
    for label, path in [("compose", layout["docker"] / "compose.env"), ("app", layout["docker"] / "secrets/app.env")]:
        require(digest(path) == lease[label + "_expected_hash"], "private_configuration_changed")
    require(containers(layout, lease)["db"] == lease["containers"]["db"] and unrelated(layout, lease) == lease["unrelated"], "protected_containers_changed")
    require(all(timer_active(timer, layout, lease) == active for timer, active in lease["timers"].items()), "timer_state_changed")
    runtime_checks(layout, lease, opened=True)
    receipt = {"run_id": lease["run_id"], "revision": layout["release"]["revision"], "tag": layout["release"]["tag"],
               "images": layout["release"]["images"], "backup": lease["backup_stream"], "preservation_verified": True,
               "phase": "complete", "completed_at": int(time.time()), "compose_files": lease["compose_files"]}
    atomic_bytes(layout["stage"] / "receipt.json", json.dumps(receipt, sort_keys=True).encode())
    layout["active"].unlink()
    return receipt


def recover_receipt(layout):
    path = safe_path(layout["stage"] / "receipt.json", layout["root"], True)
    receipt = json.loads(path.read_text())
    require(receipt.get("run_id") == layout["release"]["run_id"] and receipt.get("revision") == layout["release"]["revision"], "receipt_owner_mismatch")
    require(receipt.get("phase") in {"complete", "recovered"}, "receipt_invalid")
    verify_compose_files(layout, receipt)
    expected = layout["release"]["revision"] if receipt["phase"] == "complete" else layout["release"]["previous_revision"]
    require(manifest(layout, receipt)["commit"] == expected, "receipt_state_changed")
    maintenance = layout["down"].exists()
    require(maintenance == receipt.get("maintenance", False), "receipt_state_changed")
    observed = containers(layout, receipt)
    require(all(item["status"] == "running" and item["health"] in {"healthy", "none"} for item in observed.values()), "receipt_state_changed")
    if receipt["phase"] == "complete":
        require(all(observed[item["service"]]["image_id"] == item["id"] for item in receipt["images"]), "receipt_state_changed")
    runtime_checks(layout, receipt, opened=not maintenance)
    return {"phase": receipt["phase"], "maintenance": maintenance, "revision": expected}


def dispatch(payload):
    layout = layout_for(payload)
    action, params = payload.get("action"), payload.get("params", {})
    if action == "status":
        return status(layout)
    if action == "image-status":
        return image_status(layout)
    validate_release(layout["release"])
    with deployment_guard(layout, create=action == "stage"):
        if action == "stage":
            return stage(layout)
        if action == "recover" and read_lease(layout, required=False) is None:
            return recover_receipt(layout)
        lease = read_lease(layout)
        require(lease["release"] == layout["release"], "release_payload_changed")
        try:
            if action == "load":
                return load(layout, lease, params)
            if action == "freeze":
                return freeze(layout, lease)
            if action == "backup":
                return backup(layout, lease)
            if action == "backup-verified":
                require(lease["phase"] == "backed_up" and params == lease["backup_stream"], "backup_receipt_mismatch")
                lease["phase"] = "verified_backup"
                save_lease(layout, lease)
                return {"phase": "verified_backup"}
            if action == "switch":
                return switch(layout, lease)
            if action == "recover":
                return recover(layout, lease)
            if action == "finalize":
                return finalize(layout, lease)
            raise DeployError("action_refused")
        except Exception:
            if lease.get("mutated"):
                close_owned(layout, lease)
            raise


def emit(payload):
    try:
        data = dispatch(payload)
        if payload.get("action") != "backup":
            print(json.dumps({"ok": True, "data": data}, sort_keys=True), flush=True)
    except Exception as error:
        code = error.code if isinstance(error, DeployError) else "remote_action_failed"
        if payload.get("action") == "backup":
            print(code, file=sys.stderr)
        else:
            print(json.dumps({"ok": False, "error": {"code": code}}), flush=True)
        raise SystemExit(1)
