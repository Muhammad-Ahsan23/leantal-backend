<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AcceptInviteRequest;
use App\Http\Requests\Users\ChangeUserRoleRequest;
use App\Http\Requests\Users\InviteUserRequest;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Job;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\RefreshTokenService;
use App\Services\RegionResolver;
use App\Services\RegionRoutingRepository;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function __construct(
        protected RegionRoutingRepository $routing,
        protected RefreshTokenService $refreshTokens,
        protected NotificationService $notifications,
    ) {}

    /**
     * Lists team members. Any authenticated company member can view the
     * list — invite/remove ACTIONS are separately gated by UserPolicy,
     * this is just the read.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $users = User::on($connection)
            ->where('company_id', $user->company_id)
            ->where('status', '!=', 'removed')
            ->orderBy('created_at')
            ->get(['id', 'name', 'email', 'role', 'status', 'last_login_at', 'created_at']);

        return response()->json(['users' => $users]);
    }

    /**
     * PRD Section 88 — Owner invites a user by name, email, role.
     * Note: the Section 12 domain-match rule does NOT apply here — it is
     * explicitly signup-only; an invited user's email can be any domain.
     */
    public function invite(InviteUserRequest $request)
    {
        $actor = $request->user();

        if (!$actor->can('invite', User::class)) {
            return response()->json(['message' => 'Only the Owner can invite users.'], 403);
        }

        $connection = $actor->getConnectionName();
        $email = strtolower(trim($request->input('email')));

        // The invitation link must open the real app (PRD Section 15: app.leantal.com). Checked first, so
        // a missing FRONTEND_URL fails before anything is written.
        try {
            FrontendUrl::to('/accept-invite');
        } catch (\RuntimeException $e) {
            Log::error('Invitation not sent: '.$e->getMessage(), ['invited_by' => $actor->email]);

            return response()->json(['message' => "We couldn't create the invitation link. Please contact support."], 500);
        }

        // Global email uniqueness — the login flow (email + password only, no company selector) means
        // one email can only ever belong to ONE company, so an email that already has an account (in any
        // company or region) cannot be invited.
        //
        // One exception: an invitation that was never accepted (expired, or the link never worked)
        // must be re-sendable by the same company, otherwise that email is stuck forever. That is a
        // RE-invite: the old invitation is revoked and a new one is sent.
        $isReinvite = false;
        if ($this->routing->findRegionByEmail($email)) {
            $hasAccount = User::on($connection)->where('email', $email)->exists();
            $unacceptedHere = Invitation::on($connection)
                ->where('company_id', $actor->company_id)
                ->where('email', $email)
                ->whereIn('status', ['pending', 'expired'])
                ->exists();

            if ($hasAccount || !$unacceptedHere) {
                return response()->json(['message' => 'This email is already associated with an account.'], 422);
            }

            $isReinvite = true;
        }

        $company = Company::on($connection)->find($actor->company_id);
        $seatLimit = $company->seatLimit();

        if ($seatLimit !== null) {
            // A re-invite replaces the old invitation, so that one must not count twice.
            $usedSeats = User::on($connection)->where('company_id', $actor->company_id)->where('status', 'active')->count()
                + Invitation::on($connection)->where('company_id', $actor->company_id)->where('status', 'pending')
                    ->where('expires_at', '>', now())->where('email', '!=', $email)->count();

            if ($usedSeats >= $seatLimit) {
                return response()->json([
                    'message' => "You've reached your plan's user limit ({$seatLimit}). Upgrade to invite more users.",
                ], 422);
            }
        }

        if ($isReinvite) {
            Invitation::on($connection)
                ->where('company_id', $actor->company_id)
                ->where('email', $email)
                ->where('status', 'pending')
                ->update(['status' => 'revoked']);
        }

        $rawToken = Str::random(64);

        $invitation = Invitation::on($connection)->create([
            'company_id' => $actor->company_id,
            'email' => $email,
            'role' => $request->input('role'),
            'invited_by' => $actor->id,
            'token' => Hash::make($rawToken),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        // Reserve this email -> region mapping NOW, before the User row exists — accept-invite needs a
        // way to resolve region from email alone (same chicken-and-egg problem login has). It is an
        // upsert, so a re-invite simply refreshes it.
        $this->routing->recordUserEmail($email, $actor->company_id, RegionResolver::resolve($company->country_code));

        $inviteUrl = FrontendUrl::to('/accept-invite', ['email' => $email, 'token' => $rawToken]);
        $name = trim((string) $request->input('name'));
        $role = str_replace('_', ' ', $request->input('role'));

        try {
            Mail::raw(
                "Hi {$name},\n\nYou've been invited to join {$company->name} on LeanTal as a {$role}.\n\nClick the link below to set your password and activate your account:\n\n{$inviteUrl}\n\nThis invitation expires in 7 days.",
                function ($message) use ($email, $company) {
                    $message->to($email)->subject("You're invited to join {$company->name} on LeanTal");
                }
            );
        } catch (\Throwable $e) {
            // Nothing was delivered, so the invitation must not stay valid (or count against the seat
            // limit). The Owner can simply try again — a re-invite is allowed (see above).
            $invitation->forceFill(['status' => 'revoked'])->save();
            Log::error('Invitation email could not be sent', ['email' => $email, 'error' => $e->getMessage()]);

            return response()->json(['message' => "We couldn't send the invitation email right now. Please try again in a few minutes."], 422);
        }

        Log::info('User invited', ['email' => $email, 'company_id' => $actor->company_id, 'invited_by' => $actor->email]);

        return response()->json(['message' => 'Invitation sent.'], 201);
    }

    /**
     * Invitee sets their name + password and the account is actually
     * created. No CAPTCHA here (unlike signup/login) — the token itself,
     * emailed only to the invited address, is the protection.
     */
    public function acceptInvite(AcceptInviteRequest $request)
    {
        $email = strtolower(trim($request->input('email')));

        $region = $this->routing->findRegionByEmail($email);
        if (!$region) {
            return $this->invalidInvite();
        }

        $connection = RegionResolver::connectionFor($region);

        $invitation = Invitation::on($connection)
            ->where('email', $email)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();

        if (!$invitation || !Hash::check($request->input('token'), $invitation->token)) {
            return $this->invalidInvite();
        }

        $user = User::on($connection)->create([
            'company_id' => $invitation->company_id,
            'name' => $request->input('name'),
            'email' => $email,
            'password_hash' => Hash::make($request->input('password')),
            'role' => $invitation->role,
            'status' => 'active',
        ]);

        $invitation->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();

        Log::info('Invitation accepted', ['email' => $email, 'company_id' => $invitation->company_id]);

        return response()->json([
            'message' => 'Your account is ready. Please log in.',
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
        ], 201);
    }

    /**
     * PRD Section 68 — "Users: Owner: Invite, remove, change roles."
     *
     * Rules (PRD Sections 5, 6, 69):
     *  - Only the Owner may do this.
     *  - Only between Hiring Manager and Recruiter. There is exactly one Owner at all times, so the
     *    Owner seat is never changed here (use Transfer ownership), and nobody changes their own role.
     *  - Seats are untouched (the total number of users does not change), so no plan check is needed.
     *
     * Effects: the user's sessions are revoked so the new role applies at once everywhere (otherwise a
     * demoted Hiring Manager would keep a stale UI until the next login); the change is written to the
     * activity log (PRD Section 102 "role changes"); and the user gets an in-app notification
     * (Section 20 "Important account/security event"). Their jobs/candidates/tasks stay as they are.
     */
    public function update(ChangeUserRoleRequest $request, string $userId)
    {
        $actor = $request->user();
        $connection = $actor->getConnectionName();

        if ($actor->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can change roles.'], 403);
        }

        $target = User::on($connection)
            ->where('company_id', $actor->company_id)
            ->where('status', '!=', 'removed')
            ->find($userId);

        if (!$target) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($target->id === $actor->id) {
            return response()->json(['message' => "You can't change your own role."], 422);
        }

        if ($target->role === 'owner') {
            return response()->json(['message' => "The Owner's role can only be changed by transferring ownership."], 422);
        }

        if (!$actor->can('changeRole', $target)) {
            return response()->json(['message' => 'You do not have permission to change this role.'], 403);
        }

        $newRole = $request->input('role');
        $oldRole = $target->role;

        if ($newRole === $oldRole) {
            return response()->json([
                'message' => 'No change — that is already their role.',
                'user' => $this->roleChangeUser($target),
            ]);
        }

        DB::connection($connection)->transaction(function () use ($target, $actor, $oldRole, $newRole, $connection) {
            $target->forceFill(['role' => $newRole])->save();

            DB::connection($connection)->table('activity')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $actor->company_id,
                'actor_id' => $actor->id,
                'action' => 'user.role_changed',
                'object_type' => 'user',
                'object_id' => $target->id,
                'metadata' => json_encode([
                    'user_name' => $target->name,
                    'from_role' => $oldRole,
                    'to_role' => $newRole,
                ]),
                'created_at' => now(),
            ]);
        });

        // New role, new session: both the API tokens and the 30-day refresh sessions end now.
        $target->tokens()->delete();
        $this->refreshTokens->revokeAllForUser($connection, $target->id);

        try {
            $label = str_replace('_', ' ', $newRole);
            $this->notifications->notify($target, 'role_changed', "Your role was changed to {$label}. Please log in again.", $connection);
        } catch (\Throwable $e) {
            Log::warning('Role-change notification failed', ['user_id' => $target->id, 'error' => $e->getMessage()]);
        }

        Log::info('User role changed', ['email' => $target->email, 'from' => $oldRole, 'to' => $newRole, 'changed_by' => $actor->email]);

        return response()->json([
            'message' => "{$target->name} is now a ".str_replace('_', ' ', $newRole).'.',
            'user' => $this->roleChangeUser($target->fresh()),
        ]);
    }

    protected function roleChangeUser(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'status' => $user->status];
    }

    /**
     * PRD Section 70 — removal is a soft action: historical work (jobs,
     * candidates, tasks) is preserved, never deleted, but the assignment
     * is cleared so the work shows as unassigned rather than orphaned.
     * Sessions are revoked immediately (access must end at removal time).
     */
    public function remove(Request $request, string $userId)
    {
        $actor = $request->user();
        $connection = $actor->getConnectionName();

        $target = User::on($connection)->where('company_id', $actor->company_id)->find($userId);

        if (!$target) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (!$actor->can('remove', $target)) {
            return response()->json(['message' => 'You do not have permission to remove this user.'], 403);
        }

        $target->forceFill(['status' => 'removed', 'removed_at' => now()])->save();

        Job::on($connection)->where('assigned_user_id', $target->id)->update(['assigned_user_id' => null]);
        Candidate::on($connection)->where('assigned_user_id', $target->id)->update(['assigned_user_id' => null]);
        Task::on($connection)->where('assigned_user_id', $target->id)->update(['assigned_user_id' => null]);

        $target->tokens()->delete();
        $this->refreshTokens->revokeAllForUser($connection, $target->id);

        Log::info('User removed', ['email' => $target->email, 'removed_by' => $actor->email]);

        return response()->json(['message' => 'User removed.']);
    }

    protected function invalidInvite()
    {
        return response()->json(['message' => 'This invitation link is invalid or has expired.'], 400);
    }
}
