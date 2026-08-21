# Модуль Twilio (дополнительная разработка)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. Twilio — отдельный мониторинг денежного баланса аккаунта, который живёт рядом с БД, но к хранилищу Bitrix почти не относится.

Связь с Bitrix только через алерт в IM-чат при низком остатке.

## Зачем

Раз в день забирает баланс Twilio Account через REST API и при остатке **50 USD и ниже** шлёт сообщение в канал Bitrix IM (не чаще одного раза в сутки).

## Flow

```
Schedule twilio:collect (09:00 Europe/Moscow)
  → TwilioCollectCmd
  → CollectTwilioBalance
  → TwilioApi (Balance.json)
  → TwilioAlert (порог ≤ 50 USD, once/day)
    → BitrixIm (im.message.add)
```

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Services/TwilioApi.php` | REST Balance API |
| `app/Services/TwilioAlert.php` | Порог и once/day алерт |
| `app/Services/BitrixIm.php` | Отправка в Bitrix IM (общий с ChatApp) |
| `app/Jobs/CollectTwilioBalance.php` | Сбор баланса |
| `app/Console/Commands/TwilioCollectCmd.php` | `twilio:collect` |
| `routes/console.php` | schedule `twilio:collect` |
| `config/services.php` | `twilio.*`, `bitrix_im.*` |
| `.env` / `.env.example` | `TWILIO_*`, `BITRIX_IM_*` |

## Env

- `TWILIO_ACCOUNT_SID` — Account SID (`AC…`)
- `TWILIO_AUTH_TOKEN` — Primary Auth Token
- `TWILIO_ALERT_THRESHOLD` — порог в валюте аккаунта (по умолчанию `50`)
- `BITRIX_IM_WEBHOOK`, `BITRIX_IM_DIALOG_ID` — куда слать уведомление
