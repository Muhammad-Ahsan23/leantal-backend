<?php

namespace App\Services;

use App\Mail\InterviewInvitation;
use App\Models\Company;
use App\Models\Interview;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the candidate for Zoom interviews (Google Calendar / Outlook send their own invites, Zoom does not).
 * Best-effort and sent after the response: a mail failure is logged and recorded as a system event, never fails
 * scheduling. Needs the meeting link, so it is only sent once the Zoom meeting exists.
 */
class InterviewInviteService
{
    public function __construct(protected SystemEventLogger $systemEvents) {}

    /** @param 'REQUEST'|'CANCEL' $method */
    public function send(Interview $interview, string $method, string $connection): void
    {
        try {
            $interview->loadMissing('candidate', 'job');
            $candidate = $interview->candidate;
            if (!$candidate?->email) {
                return;
            }

            $organizer = User::on($connection)->find($interview->organizer_id);
            $company = Company::on($connection)->find($interview->company_id);
            if (!$organizer || !$company) {
                return;
            }

            $mail = new InterviewInvitation(
                $method,
                $candidate->name,
                $interview->job?->title ?? 'the role',
                $company->name,
                $organizer->name,
                $organizer->email,
                $interview->start_time->copy()->utc()->format('D, j M Y \a\t H:i').' UTC',
                $method === 'CANCEL' ? null : $interview->meeting_url,
                $this->ics($interview, $method, $candidate->email, $candidate->name, $organizer, $company->name),
            );
            $to = $candidate->email;
            $interviewId = $interview->id;
            $companyId = $interview->company_id;

            dispatch(function () use ($mail, $to, $interviewId, $companyId, $connection) {
                try {
                    Mail::to($to)->send($mail);
                } catch (Throwable $e) {
                    Log::warning('Interview invitation email failed', ['interview_id' => $interviewId, 'message' => $e->getMessage()]);
                    $this->systemEvents->log('interview_invite', 'Interview invitation email failed: '.$e->getMessage(), $companyId, str_replace('pgsql_', '', $connection), ['interview_id' => $interviewId]);
                }
            })->afterResponse();
        } catch (Throwable $e) {
            Log::warning('Interview invitation could not be prepared', ['interview_id' => $interview->id, 'message' => $e->getMessage()]);
        }
    }

    /** RFC 5545 calendar file; UTC times so every calendar app shows the candidate's own local time. */
    protected function ics(Interview $i, string $method, string $candidateEmail, string $candidateName, User $organizer, string $companyName): string
    {
        $fmt = fn ($d) => $d->copy()->utc()->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//LeanTal//Interviews//EN', 'CALSCALE:GREGORIAN', "METHOD:{$method}",
            'BEGIN:VEVENT',
            "UID:{$i->id}@leantal",
            'DTSTAMP:'.$fmt(now()),
            'SEQUENCE:'.(int) ($i->updated_at?->timestamp ?? 0),
            'DTSTART:'.$fmt($i->start_time),
            'DTEND:'.$fmt($i->end_time),
            'SUMMARY:'.$this->esc("Interview: {$i->job?->title} at {$companyName}"),
            'ORGANIZER;CN='.$this->esc($organizer->name).':mailto:'.$organizer->email,
            'ATTENDEE;CN='.$this->esc($candidateName).';RSVP=TRUE:mailto:'.$candidateEmail,
            'STATUS:'.($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED'),
        ];
        if ($method !== 'CANCEL' && $i->meeting_url) {
            $lines[] = 'LOCATION:'.$this->esc($i->meeting_url);
            $lines[] = 'DESCRIPTION:'.$this->esc("Zoom: {$i->meeting_url}");
            $lines[] = 'URL:'.$i->meeting_url;
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    protected function esc(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', ''], $text);
    }
}
