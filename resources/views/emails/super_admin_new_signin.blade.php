<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5;max-width:600px;word-wrap:break-word;overflow-wrap:break-word">
    <p>Hi {{ $adminName }},</p>
    <p>Your LeanTal Super Admin account was just signed in to from a network it has not used before.</p>
    <table cellpadding="4" style="font-size:14px;width:100%;max-width:100%;table-layout:auto">
        <tr><td><strong>Time</strong></td><td style="word-break:break-word">{{ $when }}</td></tr>
        <tr><td><strong>IP address</strong></td><td style="word-break:break-word">{{ $ip }}</td></tr>
        <tr><td><strong>Device</strong></td><td style="word-break:break-word">{{ $device }}</td></tr>
    </table>
    <p>If this was you, no action is needed.</p>
    <p><strong>If it was not you</strong>, tell the platform Owner right away so your password and authenticator can be reset.</p>
</div>
