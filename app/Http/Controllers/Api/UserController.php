<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AcceptInviteRequest;
use App\Http\Requests\Users\InviteUserRequest;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Job;
use App\Models\Task;
use App\Models\User;
use App\Services\RefreshTokenService;
use App\Services\RegionResolver;
use App\Services\RegionRoutingRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function __construct(
        protected RegionRoutingRepository $routing,
        protected RefreshTokenService $refreshTokens,
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

        // Global email uniqueness — the login flow (email + password only,
        // no company selector) means one email can only ever belong to ONE
        // company. This single check also catches "already has an account
        // somewhere" AND "already has a pending invite somewhere" — see the
        // reservation write at the bottom of this method.
        if ($this->routing->findRegionByEmail($email)) {
            return response()->json(['message' => 'This email is already associated with an account.'], 422);
        }

        $company = Company::on($connection)->find($actor->company_id);
        $seatLimit = $company->seatLimit();

        if ($seatLimit !== null) {
            $usedSeats = User::on($connection)->where('company_id', $actor->company_id)->where('status', 'active')->count()
                + Invitation::on($connection)->where('company_id', $actor->company_id)->where('status', 'pending')->where('expires_at', '>', now())->count();

            if ($usedSeats >= $seatLimit) {
                return response()->json([
                    'message' => "You've reached your plan's user limit ({$seatLimit}). Upgrade to invite more users.",
                ], 422);
            }
        }

        $rawToken = Str::random(64);

        Invitation::on($connection)->create([
            'company_id' => $actor->company_id,
            'email' => $email,
            'role' => $request->input('role'),
            'invited_by' => $actor->id,
            'token' => Hash::make($rawToken),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        // Reserve this email -> region mapping NOW, before the User row
        // exists — accept-invite needs a way to resolve region from email
        // alone (same chicken-and-egg problem login has). KNOWN LIMITATION:
        // if this invite expires or is revoked without being accepted, the
        // email stays "reserved" here — no automatic cleanup yet. Re-inviting
        // the same email after expiry will need that manually cleared.
        $this->routing->recordUserEmail($email, $actor->company_id, RegionResolver::resolve($company->country_code));

        $inviteUrl = config('app.frontend_url', 'http://localhost:5173')
            ."/accept-invite?email={$email}&token={$rawToken}";

        Mail::raw(
            "You've been invited to join {$company->name} on LeanTal as a ".str_replace('_', ' ', $request->input('role')).".\n\nClick the link below to set up your account:\n\n{$inviteUrl}\n\nThis invitation expires in 7 days.",
            function ($message) use ($email, $company) {
                $message->to($email)->subject("You're invited to join {$company->name} on LeanTal");
            }
        );

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
