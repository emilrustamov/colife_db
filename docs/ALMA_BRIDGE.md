# Alma Bridge (дополнительный модуль)

> **Не основная цель репозитория.** Перенос bridge Bitrix24 → Alma External API из `/var/www/alma` в Laravel.  
> Узкий модуль кэша клиентов — отдельно: [ALMA.md](ALMA.md).  
> DB-sync SPA (`/api/webhooks/bitrix`) — другой контур, не смешивать.

## Статус

| Этап | Статус |
|------|--------|
| A. Архитектура + аудит | сделано |
| B. Код в Laravel, `ALMA_BRIDGE_ENABLED=false` | **сделано** — пишет в Alma только после включения флага |
| C. Cutover + удаление `/var/www/alma` | ждать команду «запускаем» |

Прод по-прежнему на `alma.colifeb24apps.com` → `/var/www/alma`, пока флаг выключен и Bitrix handlers не переключены.

## Flow

```
Bitrix webhook → POST /api/webhooks/bitrix/alma
  → ProjectResolver → WebhookDispatcher
  → ApartmentService / UnitService / TenantContractService /
    LandlordContractService / ContactService

REST (X-Api-Key), resource controllers:
  POST   /api/alma-bridge/contracts              — create
  PATCH  /api/alma-bridge/contracts/{bitrixId}   — update
  POST   /api/alma-bridge/contracts/upsert
  POST   /api/alma-bridge/apartments/upsert
  POST   /api/alma-bridge/units/upsert
  POST   /api/alma-bridge/landlord-contracts
  PATCH  /api/alma-bridge/landlord-contracts/{bitrixId}
  POST   /api/alma-bridge/blocking/apply
  POST   /api/alma-bridge/termination/apply

artisan alma-bridge:upsert {type} {id} [--project=] [--create] [--update]
```

Локальных Eloquent-моделей нет: сущность = Bitrix id + Alma External API.

При `ALMA_BRIDGE_ENABLED=false`: webhook отвечает `skipped`, REST → `503`, artisan no-op/skip.

## Env

- `ALMA_BRIDGE_ENABLED` (по умолчанию `false`)
- `ALMA_API_URL` / `ALMA_API_KEY` / `ALMA_API_TIMEOUT` (уже были)
- `ALMA_WEB_URL`
- `PROJECT_DEFAULT_NAME`
- `PROJECT_DUBAI_*` / `PROJECT_HONGKONG_*` — domain, webhook URLs, **отдельный** `PROJECT_*_WEBHOOK_TOKEN` на каждый проект (токены не общие)

Маппинги полей: `config/alma_bridge_mappings.php` (из legacy `ProjectMapping`).  
Проекты/флаг: `config/alma_bridge.php`.

## Файлы

| Путь | Роль |
|------|------|
| `app/Services/AlmaBridge/*Service` | Domain: `create` / `update` / `upsert` / `apply` |
| `app/Services/AlmaBridge/WebhookDispatcher.php` | Роутинг webhook → service |
| `app/Services/AlmaApi.php` | HTTP Alma (clients + realty create/update/archive) |
| `app/Http/Controllers/Api/AlmaBridge/WebhookController.php` | Webhook |
| `app/Http/Controllers/Api/AlmaBridge/*Controller.php` | REST по ресурсам |
| `app/Console/Commands/AlmaBridgeUpsertCmd.php` | artisan |
| `storage/logs/alma_bridge-*.log` | канал `alma_bridge` |

## Cutover (этап C)

1. `ALMA_BRIDGE_ENABLED=true`, `config:clear`
2. Переключить Bitrix handlers на `https://db.colifeb24apps.com/api/webhooks/bitrix/alma` (или nginx `alma.*` → Laravel)
3. Смоук: apartment / contract / LL / blocking
4. Остановить cron `/var/www/alma`, архивировать каталог

## Ownership Alma client `external_id`

| Модуль | Право записи |
|--------|----------------|
| [ALMA.md](ALMA.md) / `AlmaContactService` | PATCH `external_id` по email (`POST /api/alma/contacts/external-id`) |
| Alma Bridge (`TenantContractService` / `LandlordContractService`) | только `createClient` во время контрактов, с lock `alma-bridge:create-client:{id}` |

Bridge **не** патчит `external_id` клиентов из contacts-кэша. Cutover checklist: убедиться, что handlers не вызывают оба write-path на один и тот же контакт одновременно.

## Auth webhook

`POST /api/webhooks/bitrix/alma`: сначала `auth.domain` → проект (`Dubai` / `HongKong`), затем токен **только этого** проекта (`PROJECT_DUBAI_WEBHOOK_TOKEN` / `PROJECT_HONGKONG_WEBHOOK_TOKEN`) либо application_token портала из `BitrixToken` / `B24_*_APPLICATION_TOKEN`. Общего токена на оба портала нет. Deal-события без handler → `422`. REST `project` — только `Dubai` \| `HongKong`.

## Безопасность (legacy)

Публичный `config.env` / `.git` / логи на `alma.colifeb24apps.com` закрыты в nginx. Ключи, которые светились, нужно **ротировать**. Новые REST — только с `X-Api-Key`.
