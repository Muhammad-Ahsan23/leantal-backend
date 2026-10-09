<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    protected const OBJECT_TYPES = ['job', 'candidate', 'application', 'task', 'interview', 'user', 'company', 'integration'];

    protected const PER_PAGE = 50;

    /**
     * PRD Section 65-66 — the Activity Log.
     *
     * Who sees what (Section 66):
     *  - Owner: the full company-wide log.
     *  - Hiring Manager: "relevant activity for accessible jobs, candidates, users and tasks". A Hiring
     *    Manager can access every job, candidate, user and task in the company (Section 8), so that is
     *    the whole log EXCEPT company-level events (ownership transfer and the like), which stay Owner-only.
     *  - Recruiter: their own activity, plus activity directly tied to the candidates / jobs / tasks
     *    assigned to them.
     * Enforced here, in the query — hiding a tab in the UI would not be enough (Section 144).
     *
     * Filters (Section 66, "simple dropdown filters"): user, action, object, date range.
     */
    public function index(Request $request)
    {
        $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'action' => ['nullable', 'string', 'max:100'],
            'object_type' => ['nullable', 'in:'.implode(',', self::OBJECT_TYPES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $user = $request->user();
        $connection = $user->getConnectionName();

        $query = DB::connection($connection)->table('activity')
            ->leftJoin('users as actor', 'actor.id', '=', 'activity.actor_id')
            ->where('activity.company_id', $user->company_id);

        if ($user->role === 'owner') {
            // full log
        } elseif (in_array($user->role, Roles::MANAGEMENT, true)) {
            $query->where('activity.object_type', '!=', 'company');
        } else {
            $this->scopeToRecruiter($query, $user, $connection);
        }

        if ($userId = $request->query('user_id')) {
            $query->where('activity.actor_id', $userId);
        }
        if ($objectType = $request->query('object_type')) {
            $query->where('activity.object_type', $objectType);
        }
        if ($action = trim((string) $request->query('action', ''))) {
            // "job" = every job.* action, "job.published" = exactly that one
            if (str_contains($action, '.')) {
                $query->where('activity.action', $action);
            } else {
                $query->where('activity.action', 'like', $this->escapeLike($action).'.%');
            }
        }
        if ($from = $request->query('from')) {
            $query->where('activity.created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to = $request->query('to')) {
            $query->where('activity.created_at', '<=', Carbon::parse($to)->endOfDay()); // the whole last day
        }

        $page = max(1, (int) $request->query('page', 1));

        $rows = $query->orderByDesc('activity.created_at')
            ->orderByDesc('activity.id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get([
                'activity.id', 'activity.actor_id', 'activity.action', 'activity.object_type',
                'activity.object_id', 'activity.metadata', 'activity.created_at',
                'actor.name as actor_name',
            ]);

        $hasMore = $rows->count() > self::PER_PAGE;

        $activity = $rows->take(self::PER_PAGE)->map(function ($row) {
            $row->metadata = is_string($row->metadata) ? (json_decode($row->metadata, true) ?: (object) []) : ($row->metadata ?? (object) []);

            return $row;
        })->values();

        return response()->json(['activity' => $activity, 'has_more' => $hasMore, 'page' => $page]);
    }

    /**
     * Recruiter: own actions OR anything tied to the candidates / jobs / tasks they are responsible for.
     */
    protected function scopeToRecruiter($query, $user, string $connection): void
    {
        $myCandidates = Candidate::on($connection)->visibleTo($user)->select('candidates.id'); // same rule as the Candidates page
        $myJobs = DB::connection($connection)->table('jobs')->where('company_id', $user->company_id)->where('assigned_user_id', $user->id)->select('id');
        $myTasks = DB::connection($connection)->table('tasks')->where('company_id', $user->company_id)->where('assigned_user_id', $user->id)->select('id');
        $myApplications = DB::connection($connection)->table('applications')
            ->where('company_id', $user->company_id)
            ->where(function ($q) use ($myCandidates, $myJobs) {
                $q->whereIn('candidate_id', $myCandidates)->orWhereIn('job_id', $myJobs);
            })
            ->select('id');

        $query->where(function ($q) use ($user, $myCandidates, $myJobs, $myTasks, $myApplications) {
            $q->where('activity.actor_id', $user->id)
                ->orWhere(fn ($w) => $w->where('activity.object_type', 'candidate')->whereIn('activity.object_id', $myCandidates))
                ->orWhere(fn ($w) => $w->where('activity.object_type', 'job')->whereIn('activity.object_id', $myJobs))
                ->orWhere(fn ($w) => $w->where('activity.object_type', 'task')->whereIn('activity.object_id', $myTasks))
                ->orWhere(fn ($w) => $w->where('activity.object_type', 'application')->whereIn('activity.object_id', $myApplications));
        });
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
