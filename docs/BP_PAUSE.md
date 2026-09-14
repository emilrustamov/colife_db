# Модуль «Пауза» (дополнительное решение)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. «Пауза» — отдельное локальное приложение Bitrix (активность bizproc), размещённое здесь для удобства деплоя.

Активность в портале: `[Эмиль Рустамов] Пауза`, код `custom_wait_v2`.

Порталы: ОАЭ (`B24_PORTAL_DOMAIN`) и ГК (`B24_HK_PORTAL_DOMAIN`).

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
| `.env` / `.env.example` | `B24_PAUSE_DRY_RUN`, `B24_PORTAL_TIMEZONE`, `B24_HK_*` |

## Инфраструктура

- **Очередь:** `bp-pauses` (отдельный supervisor-процесс)
- **Логи:** канал `bitrix_pauses`, файлы `storage/logs/bitrix_pauses-*.log`, worker log `storage/logs/queue-bp-pauses-worker.log`
- **Dry-run:** `B24_PAUSE_DRY_RUN=true` — job не вызывает `bizproc.event.send`
- **Timezone:** Bitrix присылает дату/время паузы в личном TZ пользователя, который ставит активность (не «время сервера»). Для AE и ГК парсим как `Asia/Tashkent` (`B24_PAUSE_DATETIME_TIMEZONE` / `B24_HK_PAUSE_DATETIME_TIMEZONE`). `B24_PORTAL_TIMEZONE` — отдельный параметр, на паузу не влияет.

## Режим «До даты»

Свойство активности `date` зарегистрировано как `Type: datetime` (выбор даты и времени в дизайнере БП).

Bitrix часто присылает `19.05.2026 22:53:00`, а не голую дату. Раньше время отбрасывалось и цель ставилась на `00:00` того же дня — если день уже начался, delay схлопывался до 10 секунд (пауза «не работает»).

Пример сдвига: в дизайнере «15/09/2026 21:00:00 / Время сервера», а в callback приходит `15/09/2026 23:00:00` (личная зона редактора UTC+5 vs сервер UTC+3). Парсим строку в `B24_PAUSE_DATETIME_TIMEZONE`, тогда абсолютный момент совпадает с выбранным временем сервера.

Сейчас:
- есть время (не `00:00`) → ждём до этого момента;
- только дата / `00:00` → до `00:00` выбранного дня; если уже прошло — до `00:00` следующих суток.

Форматы даты: `d.m.Y`, `d/m/Y` (ГК), `Y-m-d`, опционально с временем.
