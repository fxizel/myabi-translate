<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Models\User;
use App\Services\AdminAvailability;
use App\Services\AuditService;
use App\Services\InvitationService;
use App\Services\OperationLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with(['organization', 'creator', 'updater'])->where('is_technical', false);
        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->toString().'%';
            $query->where(fn ($q) => $q->where('name', 'like', $search)->orWhere('email', 'like', $search));
        }
        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->integer('organization_id'));
        }
        $users = $query->orderBy('name')->paginate(25)->withQueryString();
        $organisations = Organisation::orderByDesc('is_root')->orderBy('name')->get();
        $roles = User::ROLES;

        return view('admin.index', compact('users', 'organisations', 'roles'));
    }

    public function storeUser(Request $request, InvitationService $invitations)
    {
        $manualActivation = $request->boolean('activate_without_email');
        $credentials = $request->validate([
            'activate_without_email' => ['sometimes', 'boolean'],
            'password' => $manualActivation ? ['required', 'string', 'confirmed', Password::min(config('auth.password_min_length'))] : ['prohibited'],
            'password_confirmation' => $manualActivation ? ['required', 'string'] : ['prohibited'],
        ]);
        $data = $this->validatedUser($request);
        if ($manualActivation) {
            $data['active'] = true;
        }
        $user = app(AdminAvailability::class)->run(function () use ($request, $data, $invitations, $manualActivation, $credentials) {
            $user = User::create($data + [
                'password' => Str::random(64), 'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            app(AuditService::class)->record('account.created', $user, [], $user->only(['name', 'email', 'organization_id', 'roles', 'active']));
            if ($manualActivation) {
                $this->setAdministrativePassword($user, $credentials['password'], $request->user());
            } else {
                $invitations->send($user);
            }

            return $user;
        });

        return back()->with('status', __($manualActivation ? 'ui.account_created_manually' : 'Account created and invitation queued for :name.', ['name' => $user->name]));
    }

    public function activateUser(Request $request, User $user)
    {
        abort_if($user->is_technical, 403);
        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::min(config('auth.password_min_length'))],
        ]);
        DB::transaction(function () use ($request, $user, $data) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->setAdministrativePassword($user, $data['password'], $request->user());
        });

        return back()->with('status', __('ui.account_activated_manually'));
    }

    private function setAdministrativePassword(User $user, string $password, User $administrator): void
    {
        abort_if($user->is_technical, 403);
        if ($user->organization_id !== null && ! ($user->organization?->active ?? false)) {
            throw ValidationException::withMessages(['user' => __('ui.manual_activation_unavailable')]);
        }
        $activating = ! $user->active || $user->invitation_accepted_at === null;
        $before = ['active' => $user->active];
        $user->forceFill([
            'active' => true,
            'password' => $password,
            // Administrative activation does not prove ownership of the email address.
            'invitation_accepted_at' => $user->invitation_accepted_at ?? now(),
            'invitation_token' => null, 'invitation_expires_at' => null,
            'failed_login_attempts' => 0, 'locked_until' => null,
            'session_version' => $user->session_version + 1,
            'remember_token' => Str::random(60), 'updated_by' => $administrator->id,
        ])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        PasswordBroker::broker()->deleteToken($user);
        if ($activating) {
            app(AuditService::class)->record('account.activated', $user, $before, ['active' => true, 'method' => 'administrator'], $administrator);
        }
        app(AuditService::class)->record('security.password_set_by_admin', $user, [], [], $administrator);
    }

    public function updateUser(Request $request, User $user)
    {
        abort_if($user->is_technical, 403);
        $data = $this->validatedUser($request, $user);
        app(AdminAvailability::class)->run(function () use ($request, $user, $data) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($user->is_technical, 403);
            if (! Organisation::whereKey($data['organization_id'])->where('active', true)->exists()) {
                throw ValidationException::withMessages(['organization_id' => __('validation.exists', ['attribute' => 'organization_id'])]);
            }
            $before = $user->only(['name', 'email', 'organization_id', 'roles', 'active']);
            $user->fill($data)->forceFill(['updated_by' => $request->user()->id]);
            if (! $data['active'] || $user->isDirty('email')) {
                $user->session_version++;
                $user->remember_token = Str::random(60);
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $user->save();
            app(AuditService::class)->record('account.updated', $user, $before, $user->only(array_keys($before)));
            if ($before['roles'] !== $user->roles) {
                app(AuditService::class)->record('account.roles_changed', $user, ['roles' => $before['roles']], ['roles' => $user->roles]);
            }
        });

        return back()->with('status', __('Account saved.'));
    }

    public function revokeSessions(Request $request, User $user)
    {
        DB::transaction(function () use ($request, $user) {
            // Audit references the actor: lock actor and target in the same order
            // when two administrators concurrently act on each other's accounts.
            User::whereIn('id', [$request->user()->id, $user->id])->orderBy('id')->lockForUpdate()->get(['id']);
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($user->is_technical, 403);
            $user->forceFill(['session_version' => $user->session_version + 1, 'remember_token' => Str::random(60), 'updated_by' => $request->user()->id])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            app(AuditService::class)->record('account.sessions_revoked', $user);
        });

        return back()->with('status', __('All sessions revoked.'));
    }

    public function unlock(Request $request, User $user)
    {
        DB::transaction(function () use ($request, $user) {
            User::whereIn('id', [$request->user()->id, $user->id])->orderBy('id')->lockForUpdate()->get(['id']);
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($user->is_technical, 403);
            $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null, 'updated_by' => $request->user()->id])->save();
            app(AuditService::class)->record('account.unlocked', $user);
        });

        return back()->with('status', __('Account unlocked.'));
    }

    public function invite(User $user, InvitationService $invitations)
    {
        $invitations->send($user);

        return back()->with('status', __('Invitation queued.'));
    }

    public function storeOrganisation(Request $request, OperationLock $operations)
    {
        $data = $this->validatedOrganisation($request);
        $operations->run(fn () => DB::transaction(function () use ($request, $data) {
            $organisation = Organisation::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            DB::table('catalogue_state')->where('id', 1)->increment('generation');
            app(AuditService::class)->record('organization.created', $organisation, [], $data);
        }));

        return back()->with('status', __('Organization created.'));
    }

    public function updateOrganisation(Request $request, Organisation $organisation, OperationLock $operations)
    {
        $data = $this->validatedOrganisation($request, $organisation);
        $operations->run(fn () => app(AdminAvailability::class)->run(function () use ($request, $organisation, $data) {
            $organisation = Organisation::whereKey($organisation->id)->lockForUpdate()->firstOrFail();
            if ($organisation->is_root) {
                $data['active'] = true;
            }
            $before = $organisation->only(['name', 'code', 'active']);
            $organisation->fill($data)->forceFill(['updated_by' => $request->user()->id])->save();
            DB::table('catalogue_state')->where('id', 1)->increment('generation');
            app(AuditService::class)->record('organization.updated', $organisation, $before, $data);
        }, 'active'));

        return back()->with('status', __('Organization saved.'));
    }

    private function validatedOrganisation(Request $request, ?Organisation $organisation = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('organizations', 'code')->ignore($organisation?->id)],
            'active' => ['sometimes', 'boolean'],
        ]);
        $data['active'] = $request->boolean('active', true);

        return $data;
    }

    private function validatedUser(Request $request, ?User $user = null): array
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $rules = [
            'first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'organization_id' => ['required', Rule::exists('organizations', 'id')->where('active', true)],
            'roles' => ['required', 'array:'.implode(',', User::ROLES), 'min:1'],
            'active' => ['sometimes', 'boolean'], 'locale' => ['sometimes', Rule::in(['de', 'fr', 'it'])],
        ];
        foreach (User::ROLES as $role) {
            $rules['roles.'.$role] = ['sometimes', 'array', 'min:1'];
            $rules['roles.'.$role.'.*'] = [Rule::in(User::LANGUAGES)];
        }
        $data = $request->validate($rules);
        $data['name'] = trim($data['first_name'].' '.$data['last_name']);
        $data['active'] = $request->boolean('active', true);

        return $data;
    }
}
