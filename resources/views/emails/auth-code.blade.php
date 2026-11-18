<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $purpose === 'password_reset' ? 'Сброс пароля' : 'Подтверждение email' }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f4;font-family:Arial,Helvetica,sans-serif;color:#1c1917;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f5f4;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:480px;background:#ffffff;border:1px solid #e7e5e4;border-radius:12px;padding:28px 24px;">
                <tr>
                    <td>
                        <p style="margin:0 0 8px;font-size:13px;letter-spacing:.04em;text-transform:uppercase;color:#78716c;">Colife Admin</p>
                        <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;">
                            @if ($purpose === 'password_reset')
                                Сброс пароля
                            @else
                                Подтверждение email
                            @endif
                        </h1>
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#44403c;">
                            @if ($purpose === 'password_reset')
                                Используйте этот код, чтобы задать новый пароль.
                            @else
                                Используйте этот код, чтобы подтвердить email для входа в систему.
                            @endif
                        </p>
                        <p style="margin:0 0 8px;font-size:13px;color:#78716c;">Код:</p>
                        <p style="margin:0 0 20px;font-size:32px;letter-spacing:.28em;font-weight:700;font-family:Consolas,Monaco,monospace;">{{ $code }}</p>
                        <p style="margin:0;font-size:13px;line-height:1.5;color:#78716c;">
                            Код действует {{ $ttlMinutes }} мин. Если вы не запрашивали это письмо — просто проигнорируйте его.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
