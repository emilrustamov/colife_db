# Модуль Wazzup (дополнительное решение)

> **Не основная цель репозитория.** Мониторинг линий Wazzup (HK) и алерты в Bitrix IM / задачи при неоплате. Баланса в API нет — смотрим статус канала.

## Зачем

Раз в день читает список каналов Wazzup API (`GET /v3/channels`). Если статус линии в списке неоплаты (`notEnoughMoney`, `disabled`) — сообщение в IM-канал и задача в IT Dubai Tracker (как у Twilio/ChatApp).

## Flow

```
Schedule wazzup:collect (09:00 и 14:00 Europe/Moscow)
  → WazzupCollectCmd
  → CollectWazzupChannels
  → WazzupApi (channels)
  → WazzupAlert (unpaid states, once/day per channel)
      → ServiceAlertTask
      → BitrixIm
```

## Env

- `WAZZUP_API_KEY` — ключ из кабинета (Интеграция с CRM → API)
- `WAZZUP_PORTAL` (по умолчанию `HK`)
- `WAZZUP_CABINET_URL`
- `WAZZUP_ALERT_STATES` — через запятую, по умолчанию `notEnoughMoney,disabled`
- `BITRIX_IM_*` — канал уведомлений
- задачи — `BITRIX_UNITS_WEBHOOK` / `service_alert_tasks.*`

## Файлы

| Файл | Роль |
|------|------|
| `app/Services/WazzupApi.php` | REST channels |
| `app/Services/WazzupAlert.php` | Алерт + задача |
| `app/Jobs/CollectWazzupChannels.php` | Сбор |
| `app/Console/Commands/WazzupCollectCmd.php` | `wazzup:collect` |
| `routes/console.php` | schedule |
| `config/services.php` | `wazzup.*` |
