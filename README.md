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

### VDSina

Мониторинг денежного баланса VDSina + алерт в Bitrix IM при остатке ≤ порога. К хранилищу Bitrix почти не относится.

Подробности: [docs/VDSINA.md](docs/VDSINA.md).

Маркеры: `vdsina:collect`, `Vdsina*`, `CollectVdsinaBalance`, `VDSINA_*`.

### Wazzup

Мониторинг статусов линий Wazzup (HK) + алерт/задача при неоплате (`notEnoughMoney` / `disabled`). Баланса в API нет.

Подробности: [docs/WAZZUP.md](docs/WAZZUP.md).

Маркеры: `wazzup:collect`, `Wazzup*`, `CollectWazzupChannels`, `WAZZUP_*`.

### Telegram alerts

Редкие крит. алерты в `@ColifeErrorsBot` (OAuth / disk / паузы queue / supervisor / `/up`), без спама.

Подробности: [docs/TELEGRAM_ALERTS.md](docs/TELEGRAM_ALERTS.md).

Маркеры: `health:telegram-alerts`, `telegram:setup`, `Telegram*`, `TELEGRAM_*`.

### Alma contacts

Кэш клиентов Alma + API смены `external_id` по email. Не путать с bridge недвижимости.

Подробности: [docs/ALMA.md](docs/ALMA.md).

Маркеры: `alma:collect`, `AlmaContact*`, `CollectAlmaContacts`, `ALMA_*`, `CLIENT_BALANCE_API_KEY`.

### Alma Bridge (планируется)

Перенос `/var/www/alma` (Bitrix → Alma External API: апартаменты, юниты, контракты) в Laravel. Пока только аудит и архитектура; прод на `alma.colifeb24apps.com`. Не смешивать с DB-sync SPA.

Подробности: [docs/ALMA_BRIDGE.md](docs/ALMA_BRIDGE.md).

Маркеры (после этапа B): `ALMA_BRIDGE_*`, `AlmaBridge*`, `/api/webhooks/bitrix/alma`.

### Оплата сервисов (Bitrix SPA)

CallGear, Miro, ChatApp (другие линии) — даты оплаты из SPA type 1094, алерт в Bitrix IM. Twilio, VDSina и WA-линии ChatApp — только из API, карточки SPA по ним не дублируются.

Подробности: [docs/SERVICE_PAYMENTS.md](docs/SERVICE_PAYMENTS.md).

Маркеры: `payments:collect`, `ServicePay*`, `CollectServicePay`.

## OPS one-off команды

Ручные команды обслуживания (не в schedule): [docs/OPS_COMMANDS.md](docs/OPS_COMMANDS.md).
