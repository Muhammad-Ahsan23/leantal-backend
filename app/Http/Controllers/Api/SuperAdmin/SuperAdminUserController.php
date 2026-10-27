<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\SuperAdmin\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\PasswordResetService;
use App\Services\RefreshTokenService;
use App\Services\SuperAdminAuditService;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/** Every user of every company, across all three regions. */
class SuperAdminUserController extends Controller
{
    use ResolvesCompany;

    protected const REGIONS = ['us' => 'pgsql_us', 'eu' => 'pgsql_eu', 'uk' => 'pgsql_uk'];
    protected const PER_PAGE = 25;

    public function __construct(protected SuperAdminAuditService $audit) {}

    /**
     * Search by name or email, narrow by company, status or region. The three regional databases cannot be joined, so
     * each returns its newest (page x per_page) matches and they are merged — exact global ordering at any page.
     */
    public function index(Request $request)
    {
        $f = $request->validate([
            'search' => 'nullable|string|max:100',
            'company_id' => 'nullable|uuid',
            'status' => 'nullable|in:active,invited,suspended,removed',
            'region' => 'nullable|in:us,eu,uk',
            'page' => 'nullable|integer|min:1|max:1000',
        ]);
        $page = (int) ($f['page'] ?? 1);
        $rows = collect();
        $total = 0;

        foreach (self::REGIONS as $region => $connection) {
            if (!empty($f['region']) && $f['region'] !== $region) {
                continue;
            }

            $query = DB::connection($connection)->table('users as u')
                ->join('companies as c', 'c.id', '=', 'u.company_id')
                ->where('c.region', $region) // same region scoping as the Companies list
                ->when($f['company_id'] ?? null, fn ($q, $v) => $q->where('u.company_id', $v))
                ->when($f['status'] ?? null, fn ($q, $v) => $q->where('u.status', $v), fn ($q) => $q->where('u.status', '!=', 'removed'))
                ->when($f['search'] ?? null, function ($q, $v) {
                    $like = '%'.addcslashes($v, '%_\\').'%';
                    $q->where(fn ($w) => $w->where('u.email', 'ilike', $like)->orWhere('u.name', 'ilike', $like));
                });

            $total += (clone $query)->count();
            $rows = $rows->concat(
                $query->orderByDesc('u.created_at')->orderByDesc('u.id')->limit($page * self::PER_PAGE)
                    ->get(['u.id', 'u.name', 'u.email', 'u.role', 'u.status', 'u.last_login_at', 'u.created_at', 'u.company_id', 'c.name as company_name'])
                    ->each(fn ($r) => $r->region = $region)
            );
        }

        return response()->json([
            'users' => $rows->sortByDesc(fn ($r) => $r->created_at.$r->id)->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / self::PER_PAGE)), 'per_page' => self::PER_PAGE, 'total' => $total],
        ]);
    }

    /** Deactivating signs the person out everywhere and frees their seat; reactivating needs a free seat. */
    public function setStatus(Request $request, string $companyId, string $userId, RefreshTokenService $refreshTokens)
    {
        $data = $request->validate(['status' => 'required|in:active,suspended', 'reason' => 'nullable|string|max:500']);

        [$user, $connection] = $this->findUser($companyId, $userId);
        if (!$user) {
            return response()->json(['message' => 'User not found in that company.'], 404);
        }
        if ($user->role === 'owner') {
            return response()->json(['message' => 'The company owner cannot be deactivated — that would lock the whole company out. Suspend the company instead.'], 422);
        }
        if (!in_array($user->status, ['active', 'suspended'], true)) {
            return response()->json(['message' => 'Only active or deactivated users can be changed here.'], 422);
        }
        if ($user->status === $data['status']) {
            return response()->json(['message' => 'The user is already '.($data['status'] === 'active' ? 'active' : 'deactivated').'.'], 422);
        }

        if ($data['status'] === 'active') {
            $limit = Company::on($connection)->find($companyId)->seatLimit();
            if ($limit !== null && User::on($connection)->where('company_id', $companyId)->where('status', 'active')->count() >= $limit) {
                return response()->json(['message' => "The company has no free seat (limit {$limit}). Raise the limit in Billing first."], 422);
            }
        }

        $user->update(['status' => $data['status']]);
        if ($data['status'] === 'suspended') {
            $user->tokens()->delete();
            $refreshTokens->revokeAllForUser($connection, $user->id);
        }

        $this->audit->log($request->attributes->get('super_admin'), $data['status'] === 'active' ? 'user_reactivated' : 'user_deactivated', $request->ip(), 'user', $userId, [
            'company_id' => $companyId, 'reason' => $data['reason'] ?? null,
        ]);

        return response()->json(['message' => $data['status'] === 'active' ? 'User reactivated.' : 'User deactivated and signed out.', 'status' => $data['status']]);
    }

    /** Emails the user the normal reset link (valid 60 minutes). The admin never sees or sets the password. */
    public function sendPasswordReset(Request $request, string $companyId, string $userId, PasswordResetService $resets)
    {
        [$user, $connection] = $this->findUser($companyId, $userId);
        if (!$user || $user->status !== 'active') {
            return response()->json(['message' => 'Active user not found in that company.'], 404);
        }

        $key = 'admin-pw-reset:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'A reset email was already sent a few times just now. Please wait a few minutes.'], 429);
        }
        RateLimiter::hit($key, 300);

        try {
            $url = FrontendUrl::to('/reset-password', ['email' => $user->email, 'token' => $resets->generate($user, $connection)]);
            Mail::raw("A LeanTal administrator started a password reset for your account. Click the link below to choose a new password:\n\n{$url}\n\nThis link expires in 60 minutes. If you did not expect this, you can ignore this email.", fn ($m) => $m->to($user->email)->subject('Reset your LeanTal password'));
        } catch (\Throwable $e) {
            Log::error('Admin password reset email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'The email could not be sent. Check the mail settings and try again.'], 502);
        }

        $this->audit->log($request->attributes->get('super_admin'), 'user_password_reset_sent', $request->ip(), 'user', $userId, ['company_id' => $companyId]);

        return response()->json(['message' => 'Password reset email sent.']);
    }

    /** @return array{0: ?User, 1: ?string} */
    protected function findUser(string $companyId, string $userId): array
    {
        $connection = $this->connectionFor($companyId);

        return [$connection ? User::on($connection)->where('company_id', $companyId)->find($userId) : null, $connection];
    }
}
