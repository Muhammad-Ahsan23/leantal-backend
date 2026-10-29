<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">
    <p>Hi {{ $candidateName }},</p>
    @if ($method === 'CANCEL')
        <p>Your interview for <strong>{{ $jobTitle }}</strong> at {{ $companyName }} on {{ $when }} has been cancelled. We will be in touch if it is rescheduled.</p>
    @else
        <p>You are invited to an interview for <strong>{{ $jobTitle }}</strong> at {{ $companyName }}.</p>
        <table cellpadding="4" style="font-size:14px">
            <tr><td><strong>When</strong></td><td>{{ $when }}</td></tr>
            <tr><td><strong>With</strong></td><td>{{ $organizerName }}</td></tr>
            @if ($meetingUrl)
                <tr><td><strong>Zoom link</strong></td><td><a href="{{ $meetingUrl }}">{{ $meetingUrl }}</a></td></tr>
            @endif
        </table>
        <p>A calendar file is attached so you can add it to your calendar. Times in the file show in your own time zone.</p>
    @endif
    <p>Reply to this email if you have any questions.</p>
</div>
