<?php

namespace App\Console\Commands;

use App\Models\SuperAdmin;
use App\Services\SuperAdminAuthService;
use Illuminate\Console\Command;

/**
 * PRD Section 145 — MFA can not be re-enrolled through the API once it is active (that would let anyone who
 * knows the password replace the authenticator). When a Super Admin loses their device, someone with server
 * access resets it here; the admin then sets up a new authenticator from the login page.
 */
class ResetSuperAdminMfa extends Command
{
    protected $signature = 'super-admin:reset-mfa {email}';

    protected $description = 'Reset MFA for a Super Admin (lost device). Internal use only — never exposed via API.';

    public function handle(SuperAdminAuthService $auth): int
    {
        $email = strtolower(trim($this->argument('email')));
        $admin = SuperAdmin::where('email', $email)->first();

        if (!$admin) {
            $this->error("No Super Admin with email {$email}.");
            return self::FAILURE;
        }

        $auth->resetMfa($admin);

        $this->info("MFA reset for {$admin->email}. They can now use \"First time logging in? Set up two-factor authentication\" on the login page.");

        return self::SUCCESS;
    }
}
