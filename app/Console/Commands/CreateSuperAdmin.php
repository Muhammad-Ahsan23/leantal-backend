<?php

namespace App\Console\Commands;

use App\Models\SuperAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * PRD Section 145 — Super Admin accounts are NOT self-service signups
 * (unlike customer accounts) — a highly-privileged, internal-only
 * account must only ever be created by someone with direct server
 * access, never via a public API endpoint.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'super-admin:create {name} {email} {password}';

    protected $description = 'Create a new Super Admin account (internal use only — never exposed via API).';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));

        if (SuperAdmin::where('email', $email)->exists()) {
            $this->error("A Super Admin with email {$email} already exists.");
            return self::FAILURE;
        }

        $admin = SuperAdmin::create([
            'name' => $this->argument('name'),
            'email' => $email,
            'password_hash' => Hash::make($this->argument('password')),
        ]);

        $this->info("Super Admin created: {$admin->email} (id: {$admin->id})");
        $this->warn('MFA is not yet enrolled — this account must complete MFA setup via POST /api/super-admin/auth/mfa/enroll before it can log in.');

        return self::SUCCESS;
    }
}
