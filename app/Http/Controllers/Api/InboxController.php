<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\User;
use App\Support\EmailText;
use Illuminate\Http\Request;

/**
 * PRD Section 53 — "Every user has their own inbox: Inbox, Sent, Drafts, Templates.
 * No shared inboxes." Section 57 — "Strictly personal to the connected user."
 *
 * Every query here is pinned to the logged-in user's OWN rows (company_id AND
 * user_id) — there is no parameter that can widen that, and not even the Owner can
 * read another user's mail through this API.
 *
 * What the Inbox contains: replies from CANDIDATES that the user's connected
 * Gmail/Outlook received, plus replies from people the user themselves composed an
 * email to (the sync stores nothing else, by design — PRD Section 57 "linked to the
 * candidate record"). It is not a mirror of the user's whole mailbox. Sent = what the user sent or logged from LeanTal.
 * Templates already exist (Settings -> Email). Drafts are not built yet.
 */
class InboxController extends Controller
{
    protected const PER_PAGE = 25;

    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $folder = $request->query('folder', 'inbox');
        if (!in_array($folder, ['inbox', 'sent'], true)) {
            return response()->json(['message' => "Unknown folder '{$folder}'. Use inbox or sent."], 422);
        }

        $query = $this->ownEmails($user, $connection)
            ->where('direction', $folder === 'inbox' ? 'inbound' : 'outbound')
            ->with('candidate:id,name,email');

        // PRD Section 99 — Inbox search: subject, sender, body text. "Sender" is the
        // candidate (for sent mail, the recipient), so their name/email are searched too.
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            // % and _ typed by the user must match literally, not act as wildcards.
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function ($q) use ($like) {
                $q->where('subject', 'ilike', $like)
                    ->orWhere('body', 'ilike', $like)
                    ->orWhereRaw('to_emails::text ilike ?', [$like])
                    ->orWhere('from_email', 'ilike', $like)
                    ->orWhereHas('candidate', fn ($c) => $c->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
            });
        }

        $page = $query->orderByDesc('sent_at')->paginate(self::PER_PAGE);

        $emails = collect($page->items())->map(fn (Email $e) => [
            'id' => $e->id,
            'direction' => $e->direction,
            'provider' => $e->provider,
            'subject' => $e->subject,
            // The list never carries full bodies (they can be large HTML) — the
            // conversation view loads them per candidate.
            'snippet' => $this->snippet($e->body),
            'sent_at' => $e->sent_at,
            // Who a composed email went to (null for mail in a candidate's thread) and how many files it carried.
            'to' => collect($e->to_emails ?? [])->pluck('email')->values(),
            // Who a received email came from (set for replies from people who are not candidates).
            'from' => $e->from_email,
            'attachment_count' => count($e->attachments ?? []),
            'is_read' => $e->direction === 'outbound' || $e->read_at !== null,
            'candidate' => $e->candidate
                ? ['id' => $e->candidate->id, 'name' => $e->candidate->name, 'email' => $e->candidate->email]
                : null,
        ])->values();

        return response()->json([
            'emails' => $emails,
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** One of the user's OWN emails in full (for a composed email that has no candidate conversation to open). */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $email = $this->ownEmails($user, $connection)->with('candidate:id,name,email')->find($id);
        if (!$email) {
            return response()->json(['message' => 'Email not found.'], 404);
        }

        // Opening a received email reads it (a reply from a non-candidate has no conversation view that would do it).
        if ($email->direction === 'inbound' && $email->read_at === null) {
            $email->update(['read_at' => now()]);
        }

        $parts = EmailText::split($email->body);

        return response()->json(['email' => [
            'id' => $email->id,
            'direction' => $email->direction,
            'provider' => $email->provider,
            'subject' => $email->subject,
            'body_text' => $parts['text'],
            'quoted_text' => $parts['quoted'],
            'sent_at' => $email->sent_at,
            'to' => collect($email->to_emails ?? [])->pluck('email')->values(),
            'from' => $email->from_email,
            'attachments' => $email->attachments ?? [],
            'candidate' => $email->candidate ? ['id' => $email->candidate->id, 'name' => $email->candidate->name, 'email' => $email->candidate->email] : null,
        ]]);
    }

    /** PRD Section 17 — Home "Unread Messages". */
    public function unreadCount(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'unread_count' => $this->ownEmails($user, $user->getConnectionName())
                ->where('direction', 'inbound')->whereNull('read_at')->count(),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();

        $updated = $this->ownEmails($user, $user->getConnectionName())
            ->where('direction', 'inbound')->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All messages marked as read.', 'updated' => $updated]);
    }

    /** The one place that defines "my emails": this company AND this user. */
    protected function ownEmails(User $user, string $connection)
    {
        return Email::on($connection)
            ->where('company_id', $user->company_id)
            ->where('user_id', $user->id);
    }

    protected function snippet(?string $body): string
    {
        // Just what the person newly wrote, on one line — not the quoted history underneath it.
        return EmailText::snippet($body, 160);
    }
}
