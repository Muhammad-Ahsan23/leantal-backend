<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * A Job, a Candidate or a Task can be assigned to several people.
 *
 * The pivot table lists everyone assigned. `assigned_user_id` stays on the row and always holds the FIRST (primary)
 * person, so anything that still reads that column keeps working. Readers that decide who may SEE a record use
 * scopeAssignedTo() / isAssignedTo(), which accept either the column or the pivot.
 */
trait HasAssignees
{
    /** Name of the pivot table, e.g. "job_assignees". */
    abstract public function assigneePivotTable(): string;

    /** Column of the pivot table that points at this model, e.g. "job_id". */
    abstract public function assigneePivotKey(): string;

    public static function bootHasAssignees(): void
    {
        // A record created with one owner (a manually added candidate, a job created with assigned_user_id) gets its pivot row.
        static::created(function ($model) {
            if ($model->assigned_user_id) {
                DB::connection($model->getConnectionName())->table($model->assigneePivotTable())->insertOrIgnore([[
                    $model->assigneePivotKey() => $model->getKey(),
                    'user_id' => $model->assigned_user_id,
                    'created_at' => now(),
                ]]);
            }
        });
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, $this->assigneePivotTable(), $this->assigneePivotKey(), 'user_id');
    }

    /** Rows this person owns: they are the primary assignee, or one of the others. */
    public function scopeAssignedTo(Builder $query, string $userId): Builder
    {
        $table = $this->assigneePivotTable();
        $key = $this->assigneePivotKey();
        $self = $this->getTable();

        return $query->where(function ($q) use ($userId, $table, $key, $self) {
            $q->where("{$self}.assigned_user_id", $userId)
                ->orWhereExists(function ($sub) use ($userId, $table, $key, $self) {
                    $sub->selectRaw('1')->from($table)
                        ->whereColumn("{$table}.{$key}", "{$self}.id")
                        ->where("{$table}.user_id", $userId);
                });
        });
    }

    /** Rows nobody owns. */
    public function scopeUnassigned(Builder $query): Builder
    {
        $table = $this->assigneePivotTable();
        $key = $this->assigneePivotKey();
        $self = $this->getTable();

        return $query->whereNull("{$self}.assigned_user_id")
            ->whereNotExists(function ($sub) use ($table, $key, $self) {
                $sub->selectRaw('1')->from($table)->whereColumn("{$table}.{$key}", "{$self}.id");
            });
    }

    public function isAssignedTo(string $userId): bool
    {
        if ($this->assigned_user_id === $userId) {
            return true;
        }

        if ($this->relationLoaded('assignees')) {
            return $this->assignees->contains('id', $userId);
        }

        return $this->assignees()->where('users.id', $userId)->exists();
    }

    /** Everyone assigned, the primary person first. */
    public function assigneeIds(): array
    {
        $ids = $this->assignees()->pluck('users.id')->all();
        if ($this->assigned_user_id) {
            array_unshift($ids, $this->assigned_user_id);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Replaces the whole list. The first id becomes the primary assignee (`assigned_user_id`); an empty list leaves
     * the record unassigned. Returns the ids that were NOT assigned before (the people to notify).
     */
    public function setAssignees(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $before = $this->assigneeIds();

        $this->assignees()->sync($ids);
        $this->forceFill(['assigned_user_id' => $ids[0] ?? null])->save();
        $this->unsetRelation('assignees');

        return array_values(array_diff($ids, $before));
    }

    /** The JSON a record sends to the web app: the loaded assignees (primary first), as {id, name}. */
    public function toArray(): array
    {
        $array = parent::toArray();

        if ($this->relationLoaded('assignees')) {
            $array['assignees'] = $this->assigneeSummary();
        }

        return $array;
    }

    protected function assigneeSummary(): array
    {
        $primary = $this->assigned_user_id;
        $list = $this->assignees->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();

        // A record whose primary person is missing from the pivot (a row written before this feature) still shows them.
        if ($primary && !collect($list)->contains('id', $primary) && $this->relationLoaded('assignedUser') && $this->assignedUser) {
            $list[] = ['id' => $this->assignedUser->id, 'name' => $this->assignedUser->name];
        }

        usort($list, fn ($a, $b) => [$a['id'] === $primary ? 0 : 1, mb_strtolower((string) $a['name'])] <=> [$b['id'] === $primary ? 0 : 1, mb_strtolower((string) $b['name'])]);

        return $list;
    }
}
