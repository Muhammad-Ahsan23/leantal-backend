<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5;max-width:600px;word-wrap:break-word;overflow-wrap:break-word">
    <p>Hi {{ $ownerName }},</p>
    @if ($daysLeft <= 1)
        <p>Your free <strong>{{ $planName }}</strong> trial for <strong>{{ $companyName }}</strong> ends tomorrow ({{ $endsOn }}).</p>
    @else
        <p>Your free <strong>{{ $planName }}</strong> trial for <strong>{{ $companyName }}</strong> ends in {{ $daysLeft }} days ({{ $endsOn }}).</p>
    @endif
    <p>To keep adding candidates, moving them through stages and sending email, subscribe before the trial ends. After that your workspace becomes view-only until you subscribe. Nothing is deleted.</p>
    <p><a href="{{ $billingUrl }}" style="display:inline-block;background:#2f6fed;color:#ffffff;text-decoration:none;font-weight:bold;padding:10px 18px;border-radius:8px">Choose a plan</a></p>
    <p style="color:#6b7280;font-size:12.5px">Only the account Owner can manage billing. If you have already subscribed, you can ignore this email.</p>
</div>
