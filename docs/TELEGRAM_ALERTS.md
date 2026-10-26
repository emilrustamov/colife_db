# Модуль Telegram alerts (дополнительное решение)

> **Не основная цель репозитория.** Редкие критичные алерты в Telegram (`@ColifeErrorsBot`), без спама и без мониторинга «сервер упал» на этом хосте.

## Что алертит

- Bitrix OAuth (AE / ГК / TR): `refresh_token` мёртв (`invalid_grant`) или отсутствует
- Disk sync: `ERROR` / `failed > 0` в логах `bitrix_disk` за последние N часов
- Паузы: overdue/stuck jobs в очереди `bp-pauses`
- Паузы: свежие `failed_jobs` по `bp-pauses`
- Supervisor: `laravel-queue-default` / `laravel-queue-pauses` не `RUNNING`
- HTTP: `APP_URL/up` не отвечает 2xx (nginx/php недоступны снаружи)

В тексте: эмодзи, код портала (AE / HK / TR) и ссылка `https://…`.  
Один и тот же алерт — не чаще раза в сутки (cache dedupe).

## Flow

```
Schedule health:telegram-alerts (каждые 15 мин)
  → BitrixOAuthHealth
  → DiskSyncHealth
  → PauseQueueHealth
  → SupervisorHealth
  → AppHttpHealth
  → TelegramAlert.notifyOnce
  → TelegramBot.sendMessage
```

## Env

- `TELEGRAM_ALERTS_ENABLED` (по умолчанию `true`)
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_CHAT_ID`
- `TELEGRAM_DISK_LOOKBACK_HOURS` (по умолчанию `6`)
- `TELEGRAM_PAUSE_FAILED_LOOKBACK_HOURS` (по умолчанию `6`)
- `TELEGRAM_ALERT_DEDUPE_TTL` (по умолчанию `86400`)

## Setup

1. Написать боту `/start`
2. `php artisan telegram:setup` — взять `chat_id`
3. Прописать `TELEGRAM_CHAT_ID` в `.env`, `php artisan config:clear`
4. `php artisan telegram:setup --test`
5. `php artisan health:telegram-alerts`
6. `php artisan health:telegram-alerts --demo` — образцы текстов всех типов алертов

## Файлы

| Файл | Роль |
|------|------|
| `app/Services/TelegramBot.php` | Bot API |
| `app/Services/TelegramAlert.php` | dedupe + send |
| `app/Services/BitrixOAuthHealth.php` | проверка токенов |
| `app/Services/DiskSyncHealth.php` | проверка disk логов |
| `app/Services/PauseQueueHealth.php` | overdue/stuck + failed_jobs пауз |
| `app/Services/SupervisorHealth.php` | статус queue-воркеров |
| `app/Services/AppHttpHealth.php` | `APP_URL/up` |
| `app/Console/Commands/HealthTelegramAlertsCmd.php` | `health:telegram-alerts` |
| `app/Console/Commands/TelegramSetupCmd.php` | `telegram:setup` |
| `routes/console.php` | schedule |
| `config/services.php` | `telegram.*` |
