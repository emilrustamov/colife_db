# colife_db

База хранения и синхронизации данных из Bitrix24 (квартиры, юниты, контакты, диск, вебхуки и связанные сущности).

## Основное назначение

Проект — локальное хранилище данных портала Bitrix24: синхронизация сущностей, вебхуки, снимки юнитов, диск, админка.

Ключевые зоны:

- **Sync / artisan** — команды `bitrix:sync-*` в `app/Console/Commands`, расписание в `routes/console.php`
- **Webhooks** — `POST /api/webhooks/bitrix*` в `routes/api.php`
- **Disk** — синхронизация и просмотр диска Bitrix
- **Admin UI** — веб-интерфейс каталогов и пользователей

## Дополнительные модули (не цель проекта)

В репозитории также лежат отдельные разработки. Их не стоит путать с основной БД.

### «Пауза»

Локальное приложение Bitrix — активность bizproc «Пауза» (`custom_wait_v2`).

Подробности: [docs/BP_PAUSE.md](docs/BP_PAUSE.md).

Маркеры: роуты `/api/bp/*`, очередь `bp-pauses`, классы `*Pause*`, `BpController`, `RegisterActivityJob`.

### ChatApp

Мониторинг балансов диалогов ChatApp + алерт в Bitrix IM. К хранилищу Bitrix почти не относится.

Подробности: [docs/CHATAPP.md](docs/CHATAPP.md).

Маркеры: `chatapp:collect`, `ChatApp*`, `CollectDialogs*`, `DialogBalance`, `BITRIX_IM_*`.

### Twilio

Мониторинг денежного баланса Twilio + алерт в Bitrix IM при остатке ≤ порога. К хранилищу Bitrix почти не относится.

Подробности: [docs/TWILIO.md](docs/TWILIO.md).

Маркеры: `twilio:collect`, `Twilio*`, `CollectTwilioBalance`, `TWILIO_*`.

## OPS one-off команды

Ручные команды обслуживания (не в schedule): [docs/OPS_COMMANDS.md](docs/OPS_COMMANDS.md).
