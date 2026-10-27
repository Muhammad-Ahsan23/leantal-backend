<?php

namespace App\Support;

/** Reads config/admin_permissions.php — the only place permissions are defined. */
final class AdminPermissions
{
    /** @return array<string, array{label: string, actions: array<string, string>}> */
    public static function modules(): array
    {
        return config('admin_permissions');
    }

    /** @return string[] every assignable permission, e.g. "companies.suspend" */
    public static function all(): array
    {
        $all = [];
        foreach (self::modules() as $module => $definition) {
            foreach (array_keys($definition['actions']) as $action) {
                $all[] = "{$module}.{$action}";
            }
        }

        return $all;
    }

    /**
     * Unique, sorted list where any action in a module that has a "view" action also grants that "view" —
     * you cannot edit or delete something you are not allowed to see.
     *
     * @param  string[]  $permissions
     * @return string[]
     */
    public static function normalize(array $permissions): array
    {
        $modules = self::modules();
        $result = array_unique($permissions);

        foreach ($result as $permission) {
            $module = explode('.', $permission, 2)[0];
            if (isset($modules[$module]['actions']['view'])) {
                $result[] = "{$module}.view";
            }
        }

        $result = array_values(array_unique($result));
        sort($result);

        return $result;
    }
}
