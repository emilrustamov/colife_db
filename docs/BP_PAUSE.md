# Модуль «Пауза» (дополнительное решение)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. «Пауза» — отдельное локальное приложение Bitrix (активность bizproc), размещённое здесь для удобства деплоя.

Активность в портале: `[Эмиль Рустамов] Пауза`, код `custom_wait_v2`.

## Зачем

Кастомная пауза в бизнес-процессах Bitrix: ожидание N секунд или до выбранной даты, затем продолжение БП через `bizproc.event.send`.

## Flow

```
Bitrix bizproc activity
  → POST /api/bp/wait (или /api/bp/handler)
  → PauseJob в очередь bp-pauses (delay)
  → bizproc.event.send (возобновление БП)
```

Установка приложения: `POST|GET /api/bp/install` → сохранение OAuth-токенов → `RegisterActivityJob` регистрирует активность в портале.

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Http/Controllers/Api/BpController.php` | Приём callback паузы |
| `app/Http/Controllers/Api/InstallController.php` | Установка локального приложения + постановка регистрации активности |
| `app/Jobs/PauseJob.php` | Возобновление БП после задержки |
| `app/Jobs/RegisterActivityJob.php` | Регистрация активности `custom_wait_v2` |
| `app/Services/PauseDates.php` | Расчёт delay (секунды / дата, timezone портала) |
| `app/Console/Commands/RecoverPausesCommand.php` | `bitrix:recover-stuck-pauses` — разбор зависших job |
| `routes/api.php` | `/api/bp/wait`, `/api/bp/handler`, `/api/bp/install` |
| `routes/console.php` | schedule `bitrix:recover-stuck-pauses` каждые 5 минут |
| `deploy/supervisor/laravel-queue.conf` | worker `laravel-queue-pauses` → очередь `bp-pauses` |
| `config/services.php` | `services.bitrix.pause_dry_run` |
| `.env` / `.env.example` | `B24_PAUSE_DRY_RUN`, `B24_PORTAL_TIMEZONE` |

## Инфраструктура

- **Очередь:** `bp-pauses` (отдельный supervisor-процесс)
- **Логи:** канал `bitrix_pauses`, файлы `storage/logs/bitrix_pauses-*.log`, worker log `storage/logs/queue-bp-pauses-worker.log`
- **Dry-run:** `B24_PAUSE_DRY_RUN=true` — job не вызывает `bizproc.event.send`
- **Timezone:** `B24_PORTAL_TIMEZONE` (по умолчанию `Europe/Moscow`) — для режима «до даты»
