<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cleaning Report — Install</title>
    <script src="//api.bitrix24.com/api/v1/"></script>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 40px 24px;
            background: #eef2f7;
            color: #1f2f52;
        }
        .box {
            max-width: 480px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 8px 24px rgba(43, 78, 155, 0.08);
        }
        h1 { font-size: 20px; margin: 0 0 8px; color: #2b4e9b; }
        p { margin: 0; color: #5a6b85; line-height: 1.45; }
        .ok { color: #1f7a4d; }
        .err { color: #b42318; }
    </style>
</head>
<body>
<div class="box">
    <h1>Cleaning Report</h1>
    <p id="msg" class="{{ $ok ? 'ok' : 'err' }}">{{ $message }}</p>
</div>
<script>
    (function () {
        var saveUrl = @json($saveUrl);
        var canFinish = @json((bool) $ok);
        var msg = document.getElementById('msg');

        function setMsg(text, ok) {
            msg.textContent = text;
            msg.className = ok ? 'ok' : 'err';
        }

        function saveAuth(auth) {
            if (!auth || !auth.access_token || !auth.refresh_token) {
                return Promise.resolve(false);
            }
            return fetch(saveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    domain: auth.domain || '',
                    access_token: auth.access_token,
                    refresh_token: auth.refresh_token,
                    expires_in: auth.expires_in || 3600,
                    member_id: auth.member_id || '',
                    user_id: auth.user_id || null
                })
            }).then(function (r) { return r.json().then(function (data) {
                return r.ok && data.status === 'ok';
            }).catch(function () { return false; }); });
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (typeof BX24 === 'undefined') {
                setMsg('BX24 is not available. Open install from Bitrix24.', false);
                return;
            }
            BX24.init(function () {
                var auth = BX24.getAuth ? BX24.getAuth() : null;
                saveAuth(auth).then(function (saved) {
                    if (saved) {
                        setMsg('Cleaning Report app installed.', true);
                    }
                    if (canFinish && typeof BX24.installFinish === 'function') {
                        BX24.installFinish();
                    }
                }).catch(function () {
                    if (canFinish && typeof BX24.installFinish === 'function') {
                        BX24.installFinish();
                    }
                });
            });
        });
    })();
</script>
</body>
</html>
