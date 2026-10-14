"""Server transaction fixtures: no real Docker, SSH, services or private data."""
import contextlib
import importlib.util
import json
import os
import pathlib
import subprocess
import tempfile
import unittest
from unittest import mock

parents = pathlib.Path(__file__).resolve().parents
source = parents[2] / "scripts/vps-deploy-remote.py" if len(parents) > 2 else pathlib.Path(__file__).with_name("vps-deploy-remote.py")
if not source.is_file():
    source = pathlib.Path(__file__).with_name("vps-deploy-remote.py")
spec = importlib.util.spec_from_file_location("vps_remote", source)
remote = importlib.util.module_from_spec(spec)
spec.loader.exec_module(remote)


class RemoteDeploymentTests(unittest.TestCase):
    def setUp(self):
        parent = pathlib.Path(__file__).resolve().parent if os.name == "nt" else pathlib.Path(tempfile.gettempdir())
        self.temporary = tempfile.TemporaryDirectory(prefix="remote-deployment-fixture-", dir=parent)
        self.root = pathlib.Path(self.temporary.name).resolve()
        # Validate the final deletion target before TemporaryDirectory cleanup.
        self.assertTrue(self.root.is_relative_to(parent.resolve()))
        self.stage = self.root / "operations/deploy-test"
        self.stage.mkdir(parents=True)
        self.revision = "b" * 40
        self.release = {"run_id": "a" * 32, "revision": self.revision, "tag": "sha-" + self.revision[:12],
                        "previous_revision": "c" * 40, "previous_tag": "sha-" + "c" * 12,
                        "images": [{"service": "app", "ref": "myabi-translate-app:sha-" + self.revision[:12], "id": "sha256:" + "d" * 64},
                                   {"service": "myabi-web", "ref": "myabi-translate-web:sha-" + self.revision[:12], "id": "sha256:" + "e" * 64}]}
        self.layout = {"root": self.root, "stage": self.stage, "active": self.root / "operations/active-deployment.json",
                       "docker": self.root, "down": self.root / "down", "release": self.release}

    def tearDown(self):
        self.assertTrue(self.root.is_relative_to(pathlib.Path(self.temporary.name).parent.resolve()))
        self.temporary.cleanup()

    def error_code(self, code, callback):
        with self.assertRaises(remote.DeployError) as raised:
            callback()
        self.assertEqual(code, raised.exception.code)

    def test_foreign_lease_is_refused(self):
        self.layout["active"].write_text(json.dumps({"run_id": "f" * 32}))
        self.error_code("lease_owned_by_other_run", lambda: remote.read_lease(self.layout))

    def test_preexisting_maintenance_does_not_stop_services(self):
        self.layout["down"].write_bytes(b"existing-maintenance")
        with mock.patch.object(remote, "run") as command:
            self.error_code("preexisting_maintenance", lambda: remote.freeze(self.layout, {"phase": "loaded"}))
        command.assert_not_called()
        self.assertEqual(b"existing-maintenance", self.layout["down"].read_bytes())

    def test_only_previously_active_timers_are_restored(self):
        lease = {"stopped_timers": ["myabi-docker-work.timer", "myabi-docker-notifications.timer"],
                 "timers": {"myabi-docker-work.timer": True, "myabi-docker-notifications.timer": False}}
        with mock.patch.object(remote, "run") as command:
            remote.restore_timers(self.layout, lease)
        self.assertEqual([mock.call(["systemctl", "start", "myabi-docker-work.timer"], self.layout, lease)], command.call_args_list)

    def test_corrupt_image_archive_is_refused_before_docker_load(self):
        (self.stage / "images.tar.gz").write_bytes(b"corrupt-synthetic-archive")
        fake = subprocess.CompletedProcess([], 1, stdout=b"", stderr=b"")
        with mock.patch.object(remote, "run", return_value=fake), mock.patch.object(remote.subprocess, "Popen") as process:
            self.error_code("image_archive_corrupt", lambda: remote.load(self.layout, {"phase": "staged"}, {"sha256": "0" * 64}))
        process.assert_not_called()

    def test_backup_receipt_mismatch_cannot_allow_switch(self):
        lease = {"run_id": self.release["run_id"], "release": self.release, "phase": "backed_up", "mutated": False,
                 "backup_stream": {"sha256": "1" * 64, "bytes": 123}}
        self.layout["active"].write_text(json.dumps(lease))
        payload = {"action": "backup-verified", "release": self.release, "params": {"sha256": "2" * 64, "bytes": 123}}
        with mock.patch.object(remote, "layout_for", return_value=self.layout), mock.patch.object(remote, "deployment_guard", return_value=contextlib.nullcontext()), mock.patch.object(remote, "switch") as switch:
            self.error_code("backup_receipt_mismatch", lambda: remote.dispatch(payload))
        switch.assert_not_called()
        self.assertEqual("backed_up", json.loads(self.layout["active"].read_text())["phase"])

    def test_switch_requires_verified_backup(self):
        with mock.patch.object(remote, "run") as command:
            self.error_code("backup_not_verified", lambda: remote.switch(self.layout, {"phase": "backed_up"}))
        command.assert_not_called()

    def test_environment_update_preserves_all_other_bytes(self):
        original = b'# comment\r\nPRIVATE="synthetic-value"\r\nRELEASE_TAG=old\r\nAPP_REVISION=before\r\n'
        expected = b'# comment\r\nPRIVATE="synthetic-value"\r\nRELEASE_TAG=new\r\nAPP_REVISION=before\r\n'
        self.assertEqual(expected, remote.changed_env(original, "RELEASE_TAG", "new"))
        self.assertEqual(original, remote.changed_env(original, "RELEASE_VERSION", "new", optional=True))
        self.error_code("environment_key_invalid", lambda: remote.changed_env(b'RELEASE_TAG=a\nRELEASE_TAG=b\n', "RELEASE_TAG", "new"))

    @unittest.skipUnless(os.name == "posix", "Atomic fsync/Unix permissions are tested in an isolated Linux container.")
    def test_atomic_environment_file_preserves_mode_and_owner(self):
        target = self.root / "private.env"
        target.write_bytes(b"before")
        target.chmod(0o640)
        remote.atomic_bytes(target, b"after", 0o640, (os.getuid(), os.getgid()))
        self.assertEqual(b"after", target.read_bytes())
        self.assertEqual(0o640, target.stat().st_mode & 0o777)
        self.assertEqual(os.getuid(), target.stat().st_uid)
        self.assertFalse(list(self.root.glob("*.partial-*")))

    def test_recover_before_freeze_needs_no_operation_lock(self):
        lease = {"mutated": False, "owned_maintenance": False, "stopped_timers": [], "run_id": self.release["run_id"], "compose_files": {}}
        self.layout["active"].write_text(json.dumps(lease))
        with mock.patch.object(remote, "operation_guard") as operation, mock.patch.object(remote, "atomic_bytes") as receipt:
            result = remote.recover(self.layout, lease)
        operation.assert_not_called()
        receipt.assert_called_once()
        self.assertFalse(self.layout["active"].exists())
        self.assertEqual({"phase": "recovered", "maintenance": False}, result)

    def test_closing_without_ownership_receipt_keeps_maintenance_and_lease(self):
        lease = {"mutated": False, "owned_maintenance": True, "phase": "closing", "run_id": self.release["run_id"], "compose_files": {}}
        self.layout["down"].write_bytes(b"uncertain-maintenance")
        self.layout["active"].write_text(json.dumps(lease))
        with mock.patch.object(remote, "restore_timers") as timers, mock.patch.object(remote, "save_lease") as save:
            self.error_code("maintenance_receipt_missing", lambda: remote.recover(self.layout, lease))
        timers.assert_not_called()
        save.assert_called_once()
        self.assertTrue(self.layout["active"].exists())
        self.assertEqual(b"uncertain-maintenance", self.layout["down"].read_bytes())
        self.assertEqual("maintenance_recovery_required", lease["phase"])

    def test_completed_receipt_recovers_lost_response_without_switching(self):
        receipt = {"phase": "complete", "run_id": self.release["run_id"], "revision": self.revision, "images": self.release["images"], "compose_files": {}}
        (self.stage / "receipt.json").write_text(json.dumps(receipt))
        observed = {item["service"]: {"image_id": item["id"], "status": "running", "health": "healthy"} for item in self.release["images"]}
        observed["db"] = {"status": "running", "health": "healthy"}
        payload = {"action": "recover", "release": self.release}
        with mock.patch.object(remote, "layout_for", return_value=self.layout), mock.patch.object(remote, "deployment_guard", return_value=contextlib.nullcontext()), mock.patch.object(remote, "manifest", return_value={"commit": self.revision}), mock.patch.object(remote, "containers", return_value=observed), mock.patch.object(remote, "runtime_checks") as health, mock.patch.object(remote, "recover") as rollback:
            result = remote.dispatch(payload)
        rollback.assert_not_called()
        health.assert_called_once()
        self.assertEqual({"phase": "complete", "maintenance": False, "revision": self.revision}, result)

    def test_other_active_lease_prevents_idempotent_receipt_recovery(self):
        self.layout["active"].write_text(json.dumps({"run_id": "f" * 32}))
        with mock.patch.object(remote, "layout_for", return_value=self.layout), mock.patch.object(remote, "deployment_guard", return_value=contextlib.nullcontext()), mock.patch.object(remote, "recover_receipt") as receipt:
            self.error_code("lease_owned_by_other_run", lambda: remote.dispatch({"action": "recover", "release": self.release}))
        receipt.assert_not_called()

    def test_modified_compose_is_refused_before_freeze_mutations(self):
        compose = self.root / "compose.yml"
        compose.write_bytes(b"services: synthetic-initial")
        lease = {"phase": "loaded", "compose_files": remote.compose_hashes(self.layout)}
        compose.write_bytes(b"services: synthetic-changed")
        with mock.patch.object(remote, "run") as command:
            self.error_code("compose_configuration_changed", lambda: remote.freeze(self.layout, lease))
        command.assert_not_called()

    def test_zero_exit_audit_without_success_message_is_refused(self):
        healthy = subprocess.CompletedProcess([], 0, stdout=b"Database, schema and private runtime directories are healthy.", stderr=b"")
        failed = subprocess.CompletedProcess([], 0, stdout=b"Synthetic audit failure with zero exit status", stderr=b"")
        with mock.patch.object(remote, "run", side_effect=[healthy, failed]) as command:
            self.error_code("audit_check_failed", lambda: remote.runtime_checks(self.layout, {}))
        self.assertEqual(2, command.call_count)

    def test_nginx_reload_waits_for_live_master_after_failed_pid_probes(self):
        lease = {"phase": "switching"}
        probe_results = iter([
            subprocess.CompletedProcess([], 1, stdout=b"", stderr=b"Synthetic malformed PID"),
            subprocess.CompletedProcess([], 1, stdout=b"", stderr=b"Synthetic stale PID"),
            subprocess.CompletedProcess([], 0, stdout=b"", stderr=b""),
        ])
        events = []
        master_ready = False

        def command(args, layout, passed_lease, **kwargs):
            nonlocal master_ready
            self.assertIs(self.layout, layout)
            self.assertIs(lease, passed_lease)
            if args[-2:] == ["nginx", "-t"]:
                self.assertFalse(events)
                events.append("configuration")
            elif args[-3:] == ["nginx", "-s", "reload"]:
                self.assertTrue(master_ready, "Reload must wait for a successful PID/liveness probe.")
                events.append("reload")
            else:
                self.assertEqual(["exec", "-T", "myabi-web", "sh", "-c"], args[-6:-1])
                self.assertIs(False, kwargs.get("check"))
                self.assertGreater(kwargs["timeout"], 0)
                self.assertLessEqual(kwargs["timeout"], 10)
                self.assertNotIn("reload", events)
                result = next(probe_results)
                master_ready = result.returncode == 0
                events.append("ready" if master_ready else "waiting")
                return result
            return subprocess.CompletedProcess(args, 0, stdout=b"", stderr=b"")

        with mock.patch.object(remote, "run", side_effect=command), mock.patch.object(remote.time, "monotonic", return_value=100), mock.patch.object(remote.time, "sleep") as sleep:
            remote.reload_nginx(self.layout, lease)
        self.assertEqual(["configuration", "waiting", "waiting", "ready", "reload"], events)
        self.assertEqual([mock.call(1), mock.call(1)], sleep.call_args_list)

    def test_nginx_missing_master_reaches_deadline_without_reload(self):
        clock = [100.0]
        lease = {"phase": "switching"}
        probes = []

        def command(args, layout, passed_lease, **kwargs):
            if args[-2:] == ["nginx", "-t"]:
                return subprocess.CompletedProcess(args, 0, stdout=b"", stderr=b"")
            self.assertEqual(["exec", "-T", "myabi-web", "sh", "-c"], args[-6:-1])
            self.assertIs(False, kwargs.get("check"))
            probes.append(kwargs["timeout"])
            return subprocess.CompletedProcess(args, 1, stdout=b"", stderr=b"Synthetic unavailable master")

        def sleep(seconds):
            self.assertGreater(seconds, 0)
            self.assertLessEqual(seconds, 1)
            clock[0] += seconds

        with mock.patch.object(remote, "run", side_effect=command) as calls, mock.patch.object(remote.time, "monotonic", side_effect=lambda: clock[0]), mock.patch.object(remote.time, "sleep", side_effect=sleep):
            self.error_code("nginx_timeout", lambda: remote.reload_nginx(self.layout, lease))
        self.assertEqual(160.0, clock[0])
        self.assertEqual(60, len(probes))
        self.assertTrue(all(0 < timeout <= 10 for timeout in probes))
        self.assertFalse(any(call.args[0][-3:] == ["nginx", "-s", "reload"] for call in calls.call_args_list))

    def test_nginx_hung_pid_probe_is_bounded_and_cannot_reload(self):
        clock = [0.0]
        probes = []

        def command(args, layout, lease, **kwargs):
            if args[-2:] == ["nginx", "-t"]:
                return subprocess.CompletedProcess(args, 0, stdout=b"", stderr=b"")
            self.assertEqual(["exec", "-T", "myabi-web", "sh", "-c"], args[-6:-1])
            self.assertIs(False, kwargs.get("check"))
            timeout = kwargs["timeout"]
            self.assertLessEqual(timeout, 60 - clock[0])
            probes.append(timeout)
            clock[0] += timeout
            raise subprocess.TimeoutExpired(args, timeout)

        def sleep(seconds):
            self.assertLessEqual(seconds, 60 - clock[0])
            clock[0] += seconds

        with mock.patch.object(remote, "run", side_effect=command) as calls, mock.patch.object(remote.time, "monotonic", side_effect=lambda: clock[0]), mock.patch.object(remote.time, "sleep", side_effect=sleep):
            self.error_code("nginx_timeout", lambda: remote.reload_nginx(self.layout, {}))
        self.assertEqual([10, 10, 10, 10, 10, 5], probes)
        self.assertEqual(60.0, clock[0])
        self.assertFalse(any(call.args[0][-3:] == ["nginx", "-s", "reload"] for call in calls.call_args_list))


if __name__ == "__main__":
    unittest.main()
