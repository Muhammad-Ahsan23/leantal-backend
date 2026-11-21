<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Helpers for the "assign to one or more people" requests.
 *
 * The API accepts `assigned_user_ids` (a list) and, as before, a single `assigned_user_id`.
 */
final class Assignees
{
    /** The ids a request asks for, without duplicates, in the order given. */
    public static function idsFrom(array $data): array
    {
        if (array_key_exists('assigned_user_ids', $data) && is_array($data['assigned_user_ids'])) {
            $ids = $data['assigned_user_ids'];
        } elseif (!empty($data['assigned_user_id'])) {
            $ids = [$data['assigned_user_id']];
        } else {
            $ids = [];
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** The people with these ids that belong to the company, or null when any id is not one of them. */
    public static function inCompany(string $connection, string $companyId, array $ids): ?Collection
    {
        $users = User::on($connection)->where('company_id', $companyId)->whereIn('id', $ids)->get();

        return $users->count() === count($ids) ? $users : null;
    }

    /** "Ali, Sara" for the activity feed, in the order of $ids. */
    public static function names(Collection $users, array $ids): string
    {
        $byId = $users->keyBy('id');

        return collect($ids)->map(fn ($id) => $byId->get($id)?->name)->filter()->implode(', ');
    }
}
