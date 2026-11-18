<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prices Report — Install</title>
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
    </style>
</head>
<body>
<div class="box">
    <h1>Prices Report</h1>
    <p id="msg">Installing…</p>
</div>
<script>
    (function () {
        var msg = document.getElementById('msg');
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof BX24 === 'undefined') {
                msg.textContent = 'BX24 is not available. Open install from Bitrix24.';
                return;
            }
            BX24.init(function () {
                msg.textContent = 'Installation finished.';
                if (typeof BX24.installFinish === 'function') {
                    BX24.installFinish();
                }
            });
        });
    })();
</script>
</body>
</html>
