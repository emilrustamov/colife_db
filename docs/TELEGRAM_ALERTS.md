# Модуль Telegram alerts (дополнительное решение)

> **Не основная цель репозитория.** Редкие критичные алерты в Telegram (`@ColifeErrorsBot`), без спама и без мониторинга «сервер упал» на этом хосте.

## Что алертит

**Сразу (в момент ошибки):**

- OAuth: refresh упал при живом REST-вызове (`BitrixRest` → тот же текст, что health; dedupe `oauth:{domain}`)
- Паузы: `BP_WAIT_REJECTED` на `/api/bp/wait` (403 / auth)
- Паузы: `PauseJob` окончательно упал после retries
- Collect упал (раз в сутки на ключ): ChatApp AE/HK, Twilio, VDSina, Wazzup, Wikibot, `payments:collect`, Alma, landlord-finance
- Bitrix sync упал: `bitrix:sync-contacts`, SPA (`ownerships` / `unit-stays` / …)
- Disk sync упал / partial failed (`bitrix:sync-disk` / `SyncDiskJob`; dedupe `disk_sync_errors`)
- Schedule: любая scheduled-команда с non-zero exit (`ScheduledTaskFailed`); skip для sync/collect, у которых свой алерт
- Cleaning (TR): submit 500, form 403
- Job finally failed (skip `PauseJob` / `SyncDiskJob`) / Horizon long wait

**Только опросом `telegram:health` (каждые 15 мин)** — нет события «сломалось», пока сами не проверим:

- OAuth backup: токен уже мёртв, но портал никто не дергал
- Disk sync: `ERROR` в логах `bitrix_disk` за N часов (если sync не кинул exception)
- Паузы: overdue/stuck в очереди `bp-pauses` / свежие `failed_jobs`
- Storage: права на `storage/logs` / framework
- Cache: в production запрещён `CACHE_STORE=file` (ломает `Cache::lock`); Redis cache ping/lock
- Supervisor: `horizon` не `RUNNING`
- HTTP: `APP_URL/up` не 2xx

> Важно: если `/api/bp/wait` падает 500 **до** постановки в очередь (как при битых правах на логи), `PauseQueueHealth` молчит — очередь пустая. За это отвечает `StorageHealth`.

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
| `app/Services/CollectFailureAlert.php` | TG при падении `*:collect` |
| `app/Services/CleaningAlert.php` | TG при cleaning submit fail / 403 / wrong installer |
| `app/Services/PauseAlert.php` | TG при wait 403 / PauseJob failed |
| `app/Services/BitrixOAuthHealth.php` | проверка токенов |
| `app/Services/DiskSyncHealth.php` | проверка disk логов |
| `app/Services/PauseQueueHealth.php` | overdue/stuck + failed_jobs пауз |
| `app/Services/StorageHealth.php` | права `storage/logs` / framework (тихая починка; TG только если fail) |
| `app/Services/SupervisorHealth.php` | статус queue-воркеров |
| `app/Services/AppHttpHealth.php` | `APP_URL/up` |
| `app/Listeners/NotifyTelegramOnScheduledTaskFailed.php` | schedule non-zero exit → TG |
| `app/Console/Commands/TelegramHealthCmd.php` | `telegram:health` |
| `app/Console/Commands/TelegramSetupCmd.php` | `telegram:setup` |
| `routes/console.php` | schedule |
| `config/services.php` | `telegram.*` |
