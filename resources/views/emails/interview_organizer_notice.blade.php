<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">
    <p>Hi {{ $organizerName }},</p>
    @if ($method === 'CANCEL')
        <p>The interview with <strong>{{ $candidateName }}</strong> for <strong>{{ $jobTitle }}</strong> on {{ $when }} has been cancelled.</p>
    @else
        <p>Your interview with <strong>{{ $candidateName }}</strong> for <strong>{{ $jobTitle }}</strong> is scheduled.</p>
        <table cellpadding="4" style="font-size:14px">
            <tr><td><strong>When</strong></td><td>{{ $when }}</td></tr>
            @if ($meetingUrl)
                <tr><td><strong>Zoom link (host)</strong></td><td><a href="{{ $meetingUrl }}">{{ $meetingUrl }}</a></td></tr>
            @endif
        </table>
        <p>You can also open the link anytime from the Calendar page in LeanTal.</p>
    @endif
</div>
