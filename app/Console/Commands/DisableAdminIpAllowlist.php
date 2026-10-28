<?php

namespace App\Console\Commands;

use App\Services\AdminIpAllowlistService;
use Illuminate\Console\Command;

/** Break-glass for an admin who locked themselves out with the IP allowlist. Needs server access, never exposed via API. */
class DisableAdminIpAllowlist extends Command
{
    protected $signature = 'super-admin:allowlist-off';

    protected $description = 'Turn the Super Admin IP allowlist off (lock-out recovery). The list itself is kept.';

    public function handle(AdminIpAllowlistService $allowlist): int
    {
        $allowlist->disable();
        $this->info('IP allowlist is now OFF (takes effect within ~15 seconds). Sign in and fix the list under Security.');

        return self::SUCCESS;
    }
}
