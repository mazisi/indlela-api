{{-- White-label: the workspace's logo, name and colour; nothing of Indlela. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $brand }}</title>
</head>
<body style="margin: 0; padding: 0; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #f3f4f6; padding: 24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%; background: #ffffff; border-top: 4px solid {{ $color }};">
                <tr>
                    <td style="padding: 20px 28px 8px 28px;">
                        @if ($logo && isset($message))
                            <img src="{{ $message->embedData($logo['bytes'], 'logo', $logo['mime']) }}" alt="{{ $brand }}" style="max-height: 44px; max-width: 220px;">
                        @else
                            <span style="font-size: 18px; font-weight: bold; color: {{ $color }};">{{ $brand }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 28px 4px 28px; font-size: 14px; line-height: 1.6;">
                        {!! $body !!}
                    </td>
                </tr>
                @if ($url && $buttonText)
                    <tr>
                        <td style="padding: 8px 28px 24px 28px;">
                            <a href="{{ $url }}" style="display: inline-block; background: {{ $color }}; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: bold; padding: 10px 20px; border-radius: 4px;">{{ $buttonText }}</a>
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="padding: 14px 28px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280; line-height: 1.5;">
                        Sent by {{ $brand }}{{ $clientName !== '' ? ' for '.$clientName : '' }}.
                        @if ($url)
                            <br>If the button doesn't work, copy this link into your browser: <a href="{{ $url }}" style="color: {{ $color }};">{{ $url }}</a>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
