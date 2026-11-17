<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Emails\BulkSendEmailRequest;
use App\Jobs\SendBulkEmailJob;
use App\Models\Application;
use App\Models\EmailTemplate;
use App\Models\OAuthToken;
use Illuminate\Support\Str;

class BulkEmailController extends Controller
{
    // The only {{variables}} a bulk send can fill for every recipient. ({{interview.date}} / {{meeting_link}} need a specific interview.)
    public const VARIABLES = ['candidate.first_name', 'job.title', 'company.name', 'recruiter.name'];

    // Seconds between two emails of one batch: a personal inbox is paced gently (about 20 a minute), our own SMTP faster.
    private const SPACING = ['send_personal' => 3, 'send_system' => 1];

    /**
     * POST /applications/bulk-email. Checks everything that can be checked up front (so a mistake is one clear error, not
     * 100 failures later), skips what cannot be sent, then queues one SendBulkEmailJob per candidate and answers at once.
     */
    public function store(BulkSendEmailRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $data = $request->validated();

        // 1) The message itself: a template of this company, or the typed subject + body. Unknown / unavailable variables are refused now.
        $subject = $data['subject'] ?? null;
        $body = $data['body'] ?? null;
        if (!empty($data['template_id'])) {
            $template = EmailTemplate::on($connection)->where('company_id', $user->company_id)->find($data['template_id']);
            if (!$template) {
                return response()->json(['message' => 'Email template not found.'], 422);
            }
            $subject = $template->subject;
            $body = $template->body;
        }
        preg_match_all('/\{\{([a-zA-Z0-9_.]+)\}\}/', ($subject ?? '').' '.($body ?? ''), $found);
        $unknown = array_values(array_diff(array_unique($found[1] ?? []), self::VARIABLES));
        if ($unknown) {
            return response()->json(['message' => 'These variables are not available in a bulk email: '.implode(', ', $unknown).'. You can use: '.implode(', ', self::VARIABLES).'.'], 422);
        }

        // 2) Sending from the user's own inbox needs one connected.
        if ($data['mode'] === 'send_personal') {
            $connected = OAuthToken::on($connection)->where('user_id', $user->id)->whereIn('provider', ['google', 'microsoft'])->whereNull('disconnected_at')->exists();
            if (!$connected) {
                return response()->json(['message' => 'Connect your Gmail or Outlook account first (Settings > Integrations) to send from your own inbox, or choose the system address.'], 422);
            }
        }

        // 3) Who can actually be emailed: found, allowed, has an address, and only once per person.
        $applications = Application::on($connection)->with('candidate')->whereIn('id', $data['application_ids'])->get()->keyBy('id');
        $skipped = [];
        $seen = [];
        $toQueue = [];
        foreach ($data['application_ids'] as $id) {
            $application = $applications->get($id);
            $candidate = $application?->candidate;
            if (!$application || !$candidate) {
                $skipped[] = ['id' => $id, 'name' => null, 'message' => 'Application not found.'];
            } elseif (!$user->can('update', $candidate)) {
                $skipped[] = ['id' => $id, 'name' => $candidate->name, 'message' => 'You do not have permission to email this candidate.'];
            } elseif (!$candidate->email) {
                $skipped[] = ['id' => $id, 'name' => $candidate->name, 'message' => 'This candidate has no email address.'];
            } elseif (isset($seen[$candidate->id])) {
                $skipped[] = ['id' => $id, 'name' => $candidate->name, 'message' => 'Already included once in this send.'];
            } else {
                $seen[$candidate->id] = true;
                $toQueue[] = $id;
            }
        }

        // 4) One queued job per candidate, spaced out.
        $batchId = (string) Str::uuid();
        $step = self::SPACING[$data['mode']];
        foreach ($toQueue as $i => $applicationId) {
            SendBulkEmailJob::dispatch(
                $connection, $batchId, $applicationId, $user->id, $data['mode'],
                $data['template_id'] ?? null, $data['template_id'] ?? null ? null : $subject, $data['template_id'] ?? null ? null : $body
            )->delay(now()->addSeconds($i * $step));
        }

        return response()->json([
            'batch_id' => $batchId,
            'queued' => count($toQueue),
            'skipped' => $skipped,
            // Roughly how long the last email will wait, so the app can say "about 2 minutes".
            'estimated_seconds' => max(0, (count($toQueue) - 1) * $step),
        ], 202);
    }
}
