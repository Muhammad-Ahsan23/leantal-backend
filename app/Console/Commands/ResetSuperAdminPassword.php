<?php

namespace App\Console\Commands;

use App\Models\SuperAdmin;
use App\Models\SuperAdminToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Forgotten Super Admin password. Like super-admin:create and super-admin:reset-mfa this needs server access and is
 * never exposed through the API. The password is typed at a hidden prompt (not as an argument), so it never lands in
 * the shell history. MFA is left untouched; every open session is signed out.
 */
class ResetSuperAdminPassword extends Command
{
    protected $signature = 'super-admin:reset-password {email} {--password= : Skip the prompt (non-interactive use only)}';

    protected $description = 'Set a new password for a Super Admin (forgotten password). Internal use only — never exposed via API.';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $admin = SuperAdmin::where('email', $email)->first();

        if (!$admin) {
            $this->error("No Super Admin with email {$email}.");
            return self::FAILURE;
        }

        $password = $this->option('password');
        if ($password === null || $password === '') {
            $password = $this->secret('New password (min 12 characters)');
            $confirm = $this->secret('Repeat the new password');
            if ($password !== $confirm) {
                $this->error('The two passwords do not match. Nothing was changed.');
                return self::FAILURE;
            }
        }

        if (strlen($password) < 12) {
            $this->error('Password must be at least 12 characters. Nothing was changed.');
            return self::FAILURE;
        }

        $admin->forceFill(['password_hash' => Hash::make($password)])->save();
        SuperAdminToken::where('super_admin_id', $admin->id)->delete(); // sign out every open session

        $this->info("Password updated for {$admin->email}. All sessions were signed out. MFA was not changed.");

        return self::SUCCESS;
    }
}
