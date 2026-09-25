<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Emails\SendEmailRequest;
use App\Models\Candidate;
use App\Models\Email;
use App\Services\EmailService;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function __construct(protected EmailService $emails) {}

    /**
     * PRD Section 53 — the candidate's email thread. Same access rule as
     * Notes/Activity: if you can view this candidate, you can see the
     * emails tied to them.
     */
    public function index(Request $request, string $candidateId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($candidateId);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('view', $candidate)) {
            return response()->json(['message' => 'You do not have permission to view this candidate.'], 403);
        }

        $emails = Email::on($connection)->where('candidate_id', $candidateId)
            ->with('user:id,name')
            ->orderBy('sent_at')
            ->get();

        return response()->json(['emails' => $emails]);
    }

    /**
     * PRD Section 55-58 — two modes:
     * - mode=send: actually sends via our own SMTP right now (template
     *   or free text) — this is what powers rejection emails.
     * - mode=log: just records an email the staff member already sent
     *   from their own connected inbox (manual bookkeeping until live
     *   Gmail/Outlook sync is wired).
     *
     * Same permission as adding a note (CandidatePolicy::update).
     */
    public function store(SendEmailRequest $request, string $candidateId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($candidateId);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('update', $candidate)) {
            return response()->json(['message' => 'You do not have permission to email this candidate.'], 403);
        }

        $data = $request->validated();

        try {
            if ($data['mode'] === 'send') {
                $email = $this->emails->sendSystemEmail(
                    $candidate,
                    $data['template_id'] ?? null,
                    $data['subject'] ?? null,
                    $data['body'] ?? null,
                    $data['variables'] ?? [],
                    $user,
                    $connection
                );
            } else {
                $email = $this->emails->logManualEmail($candidate, $user, $data, $connection);
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['email' => $email], 201);
    }
}
