<!doctype html>
<html lang="{{ $lang }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f7f4;font-family:Arial,Tahoma,sans-serif;color:#193329;">
    <div style="display:none!important;visibility:hidden;opacity:0;height:0;overflow:hidden;color:transparent;">
        {{ $heading }} — {{ $expiry }}
    </div>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f3f7f4;">
        <tr><td align="center" style="padding:36px 16px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;background:#ffffff;border:1px solid #e0e9e2;border-radius:16px;overflow:hidden;">
                <tr><td style="background:#1d6544;padding:24px 32px;text-align:center;">
                    <span style="font-size:25px;font-weight:700;letter-spacing:1px;color:#ffffff;">{{ $brand }}</span>
                </td></tr>
                <tr><td style="padding:32px 36px;text-align:{{ $direction === 'rtl' ? 'right' : 'left' }};direction:{{ $direction }};">
                    <h1 style="margin:0 0 20px;font-size:25px;line-height:1.4;color:#194e36;">{{ $heading }}</h1>
                    <p style="margin:0 0 12px;font-size:16px;line-height:1.8;">{{ $greeting }}</p>
                    <p style="margin:0 0 24px;font-size:15px;line-height:1.9;color:#40544a;">{{ $intro }}</p>
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                        <tr><td style="padding:22px 14px;background:#edf6f0;border:1px solid #cfe7d7;border-radius:12px;text-align:center;">
                            <div style="font-size:12px;color:#416d55;line-height:1.6;">{{ $codeCaption }}</div>
                            <div dir="ltr" style="margin:10px 0;font-family:Arial,sans-serif;font-size:36px;line-height:1.3;letter-spacing:9px;font-weight:700;color:#194e36;text-align:center;">{{ $code }}</div>
                            <div style="font-size:13px;color:#416d55;line-height:1.6;">{{ $expiry }}</div>
                        </td></tr>
                    </table>
                    <p style="margin:26px 0 10px;font-size:14px;line-height:1.9;color:#3f5549;">{{ $security }}</p>
                    <p style="margin:0 0 24px;font-size:14px;line-height:1.9;color:#3f5549;">{{ $ignore }}</p>
                    @if ($supportEmail)
                        <p style="margin:0 0 24px;font-size:14px;line-height:1.8;color:#3f5549;">
                            {{ $supportLabel }}
                            <a href="mailto:{{ $supportEmail }}" style="color:#1d6544;font-weight:bold;text-decoration:underline;word-break:break-all;">{{ $supportEmail }}</a>
                        </p>
                    @endif
                    <p style="margin:0;font-size:14px;line-height:1.9;color:#3f5549;">{{ $signoff }}<br><strong>{{ $team }}</strong></p>
                </td></tr>
            </table>
            <p style="max-width:560px;margin:18px 10px 0;font-size:12px;line-height:1.8;color:#6f8074;text-align:center;">{{ $automatic }}</p>
        </td></tr>
    </table>
</body>
</html>
