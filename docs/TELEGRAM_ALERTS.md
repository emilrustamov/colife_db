# Модуль Telegram alerts (дополнительное решение)

> **Не основная цель репозитория.** Редкие критичные алерты в Telegram (`@ColifeErrorsBot`), без спама и без мониторинга «сервер упал» на этом хосте.

## Что алертит

**Сразу (в момент ошибки, без ожидания schedule):**

- Паузы: `BP_WAIT_REJECTED` на `/api/bp/wait` (403 / auth)
- Паузы: `PauseJob` окончательно упал после retries

**По расписанию `telegram:health` (каждые 15 мин):**

- Bitrix OAuth (AE / ГК / TR): `refresh_token` мёртв (`invalid_grant`) или отсутствует
- Disk sync: `ERROR` / `failed > 0` в логах `bitrix_disk` за последние N часов
- Паузы: overdue/stuck jobs в очереди `bp-pauses`
- Паузы: свежие `failed_jobs` по `bp-pauses`
- Supervisor: `laravel-queue-default` / `laravel-queue-pauses` не `RUNNING`
- HTTP: `APP_URL/up` не отвечает 2xx (nginx/php недоступны снаружи)

В тексте: эмодзи, код портала (AE / HK / TR) и ссылка `https://…`.  
Dedupe: schedule — раз в сутки; мгновенные паузы — раз в час на портал (`TELEGRAM_INSTANT_DEDUPE_TTL`).

## Flow

```
BP wait reject / PauseJob::failed
  → PauseAlert → TelegramAlert.notifyOnce → Telegram (сразу)

Schedule telegram:health (каждые 15 мин)
  → BitrixOAuthHealth / DiskSyncHealth / PauseQueueHealth / …
  → TelegramAlert.notifyOnce
```

## Env

- `TELEGRAM_ALERTS_ENABLED` (по умолчанию `true`)
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_CHAT_ID`
- `TELEGRAM_DISK_LOOKBACK_HOURS` (по умолчанию `6`)
- `TELEGRAM_PAUSE_FAILED_LOOKBACK_HOURS` (по умолчанию `6`)
- `TELEGRAM_ALERT_DEDUPE_TTL` (по умолчанию `86400`)
- `TELEGRAM_INSTANT_DEDUPE_TTL` (по умолчанию `3600`) — мгновенные алерты пауз

## Setup

Личный чат с ботом:

1. Написать боту `/start`
2. `php artisan telegram:setup` — взять `chat_id`
3. Прописать `TELEGRAM_CHAT_ID` в `.env`, `php artisan config:clear`
4. `php artisan telegram:setup --test`
5. `php artisan telegram:health`
6. `php artisan telegram:health --demo` — образцы текстов всех типов алертов

Канал (например [ColifeErrors](https://t.me/+l3EnXkoq0HZlNTdi)):

1. Добавить бота в канал **администратором** с правом публиковать сообщения
2. Опубликовать в канале любой пост (чтобы Bot API получил `channel_post`)
3. `php artisan telegram:setup` — взять `chat_id` с `type=channel` (обычно `-100…`)
4. Прописать этот id в `TELEGRAM_CHAT_ID`, `php artisan config:clear`
5. `php artisan telegram:setup --test`

Приватный канал без `@username` — только числовой `chat_id`. Публичный можно указать как `@channelusername`.

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
| `app/Console/Commands/TelegramHealthCmd.php` | `telegram:health` |
| `app/Console/Commands/TelegramSetupCmd.php` | `telegram:setup` |
| `routes/console.php` | schedule |
| `config/services.php` | `telegram.*` |
