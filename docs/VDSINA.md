# Модуль VDSina (дополнительная разработка)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. VDSina — отдельный мониторинг баланса хостинг-аккаунта, который живёт рядом с БД, но к хранилищу Bitrix почти не относится.

Связь с Bitrix только через алерт в IM-чат при низком остатке.

Публичный API: [VDSina Public API](https://www.vdsina.com/tech/api).

## Зачем

Раз в день забирает баланс аккаунта VDSina через REST API (`GET /account.balance`) и прогноз отключения (`GET /account`). При остатке **основного баланса ≤ $50** шлёт сообщение в канал Bitrix IM (не чаще одного раза в сутки).

## Flow

```
Schedule vdsina:collect (09:00 Europe/Moscow)
  → VdsinaCollectCmd
  → CollectVdsinaBalance
  → VdsinaApi (account.balance + account)
  → VdsinaAlert (порог ≤ $50, once/day)
    → BitrixIm (im.message.add)
```

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Services/VdsinaApi.php` | REST Account API |
| `app/Services/VdsinaAlert.php` | Порог и once/day алерт |
| `app/Services/BitrixIm.php` | Отправка в Bitrix IM (общий с ChatApp/Twilio) |
| `app/Jobs/CollectVdsinaBalance.php` | Сбор баланса |
| `app/Console/Commands/VdsinaCollectCmd.php` | `vdsina:collect` |
| `routes/console.php` | schedule `vdsina:collect` |
| `config/services.php` | `vdsina.*`, `bitrix_im.*` |
| `.env` / `.env.example` | `VDSINA_*`, `BITRIX_IM_*` |

## Env

- `VDSINA_API_TOKEN` — постоянный токен из личного кабинета VDSina (Bearer)
- `VDSINA_API_URL` — базовый URL (по умолчанию `https://userapi.vdsina.com/v1`)
- `VDSINA_ALERT_THRESHOLD` — порог основного (`real`) баланса в USD (по умолчанию `50`)
- `VDSINA_CABINET_URL` — ссылка в алерте (по умолчанию `https://cp.vdsina.com/vds/list`)
- `BITRIX_IM_WEBHOOK`, `BITRIX_IM_DIALOG_ID` — куда слать уведомление
