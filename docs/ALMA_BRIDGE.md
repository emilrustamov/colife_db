# Alma Bridge → colife_db (аудит и архитектура)

> **Не основная цель репозитория.** Планируемый перенос bridge Bitrix24 → Alma External API из `/var/www/alma` в Laravel-модуль colife_db.  
> **Сейчас:** только документ. Код не перенесён, webhook не переключён, Alma не вызывается из нового контура.  
> Узкий модуль кэша клиентов — отдельно: [ALMA.md](ALMA.md). Не путать с bridge.

## Статус этапов

| Этап | Статус | Триггер |
|------|--------|---------|
| **A.** Архитектура + аудит (этот документ) | сделано | — |
| **B.** Перенос/рефакторинг в Laravel, `ALMA_BRIDGE_ENABLED=false` | ждать | команда «переноси» |
| **C.** Cutover + удаление `/var/www/alma` | ждать | команда «запускаем» |

## Контекст

Сейчас bridge живёт отдельно:

- путь: `/var/www/alma`
- хост: `alma.colifeb24apps.com` (nginx → `root /var/www/alma`)
- объём: ~4.4k LOC процедурного PHP
- поток: **односторонний** Bitrix24 → Alma External API
- своей БД нет

В colife_db уже есть:

- **Alma contacts** — `alma:collect`, `POST /api/alma/contacts/external-id`, логи `alma_contacts-*.log` — только клиенты / `external_id`
- **DB sync webhook** — `POST /api/webhooks/bitrix` пишет SPA 144/148/167/183 в локальные таблицы (`apartments`, `units`, `unit_stays`, …)

Одинаковые `entityTypeId`, **разные цели**. Смешивать DB-зеркало и Alma bridge нельзя.

---

## Аудит `/var/www/alma`

### Структура

| Зона | Файлы | Роль |
|------|-------|------|
| Entry webhook | `index.php` | Роутинг по `event` + `ENTITY_TYPE_ID` → `sync*` |
| Mapping | `Core/Config/ProjectMapping.php` (~650 LOC) | Dubai/HK: entityTypeId, UF-поля, archive/allowed стадии, типы контрактов |
| Config | `Core/Config/Config.php`, `config.env` | Env + project webhooks |
| HTTP Alma | `Api/BaseApi.php`, `AppartApi`, `TenantContractApi`, `LandlordContractApi`, `UnitApi`, `ClientApi`, … | POST/PATCH realty + clients |
| HTTP Bitrix | `Core/Bitrix24Rest.php` | Вызовы через **incoming webhook URL** портала (`PROJECT_*_WEBHOOK_URL`) |
| Helpers | `Api/SyncHelpers.php`, `ListSyncHelpers.php` | Даты списков, echo-skip, комментарии в таймлайн B24 |
| Lists | `BlockingAppartApi.php`, `TerminationAppartApi.php` | Блокировки / расторжения (GET/робот, не ветка dynamic в `index.php`) |
| Логи | `Core/Logger.php` → `logs/{dubai\|hongkong}/YYYY-MM-DD/HH.log` | По проекту и часу |
| Cron | `scripts/cron.sh` (ежедневно 03:00) | Только чистка логов старше 30 дней |

### Поток синхронизации

```text
Bitrix24
  → webhook https://alma.colifeb24apps.com/
  → index.php
      → syncApartment (SPA apt, Dubai 144 / HK свои id)
      → syncUnitById (только HK unit)
      → syncTenantContract (контракт арендатора, Dubai 183)
      → syncLandlordContract (LL, Dubai 148)
      → syncContactIfExists (контакт: только PATCH если уже есть в Alma)
  → Alma External API

Списки blocking / termination
  → ручной GET / робот → BlockingAppartApi / TerminationAppartApi
  → Alma
```

Особенности:

- **Dubai:** webhook на юнит 167 **игнорируется** (юниты через робот или каскад `syncRooms` из апартамента).
- **HongKong:** юнит синхронизируется через webhook; другие `entityTypeId`.
- Выбор проекта: host / domain HK / type ids HK / deal type 2 → иначе Dubai.

### Сильные стороны

- Предметная логика и матрица полей отлажены в проде; README + `Documentation/*` подробные.
- Защита от петли: `skip_if_integration_update` + echo от комментария ошибки Alma в таймлайне.
- Архивные стадии контракта (`DT183_28:FAIL`, `DT183_28:SUCCESS`) → archive usage, не PATCH.

### Проблемы и риски

1. **Архивный usage после POST с прошлыми датами** (кейс SPA 183 / 11702): create с `end_date` в прошлом → Alma архивирует → дальше `It is forbidden to edit the archive usage`. Для обычного tenant sync нет ветки release `external_id` + recreate (есть только в `upsertSyntheticContract`).
2. **Синхронный webhook** — вся работа в HTTP-запросе Bitrix; риск таймаутов при каскаде rooms/termination.
3. **Два HTTP-стека Bitrix** — bridge на incoming webhooks; colife_db на OAuth (`BitrixRest`). При переносе для паритета оставить `PROJECT_*_WEBHOOK_URL`, не подменять OAuth без проверки прав.
4. **Процедурный стиль** — глобальные `function sync*` + `require_once`, нет DI/тестов.
5. **Дублирование HTTP к Alma** — `BaseApi`/`ClientApi` vs Laravel `AlmaApi` (сейчас только clients).
6. **Контакты** — webhook только обновляет существующего клиента Alma; создание — внутри контракта.
7. **Секреты** в `config.env` рядом с кодом.

### Пересечение entityTypeId (Dubai, ориентир)

| SPA | В bridge | В colife_db DB sync |
|-----|----------|---------------------|
| 144 апартамент | → Alma unit/building | → `apartments` |
| 167 юнит | Dubai: skip webhook / cascade rooms | → `units` |
| 183 контракт тенанта | → Alma tenant_contracts | → `unit_stays` |
| 148 LL-контракт | → Alma owner_contracts | → `apartment_ownerships` |

После cutover handlers Alma указывают на Laravel; handlers `/api/webhooks/bitrix` для DB **не трогать**.

---

## Целевая архитектура в Laravel

Доп. модуль (как Пауза / ChatApp). Флаг **`ALMA_BRIDGE_ENABLED=false`** до явного cutover.

```text
POST /api/webhooks/bitrix/alma
artisan alma-bridge:sync-*
        │
        ▼
AlmaBridgeProjectResolver  →  AlmaBridgeEntityRouter
        │
        ├─ AlmaApartmentSync
        ├─ AlmaUnitSync
        ├─ AlmaTenantContractSync
        ├─ AlmaLandlordContractSync
        ├─ AlmaBlockingSync
        ├─ AlmaTerminationSync
        └─ AlmaContactBridgeSync
                │
                ├─ AlmaApi (расширенный: units/rooms/contracts/archive + clients)
                ├─ BitrixWebhookClient (PROJECT_*_WEBHOOK_URL, не OAuth BitrixRest)
                └─ log channel alma_bridge
```

### Планируемые файлы (этап B)

- `config/alma_bridge.php` — содержимое `ProjectMapping` + project webhooks/domains
- `app/Services/AlmaBridge/*` — sync-сервисы (1 сущность = 1 класс)
- `app/Http/Controllers/Api/AlmaBridgeWebhookController.php`
- `app/Console/Commands/AlmaBridge*` — ручной sync (паритет GET `/Api/*.php`)
- расширение `app/Services/AlmaApi.php`
- `app/Services/BitrixWebhookClient.php` (или рядом в `AlmaBridge/`)
- канал лога `alma_bridge` → `storage/logs/alma_bridge-*.log`
- `.env`: `ALMA_BRIDGE_ENABLED`, `PROJECT_DUBAI_*`, `PROJECT_HONGKONG_*` (из текущего `config.env`)

### Поведение до cutover (после этапа B)

- Роут есть, при `ALMA_BRIDGE_ENABLED=false` — ignored / 503, **в Alma не пишет**.
- `alma.colifeb24apps.com` → `/var/www/alma` продолжает работать.

### Cutover (этап C, только по команде «запускаем»)

1. `ALMA_BRIDGE_ENABLED=true`.
2. Переключить Bitrix outgoing webhooks / роботов на `https://db.colifeb24apps.com/api/webhooks/bitrix/alma` (или nginx alias `alma.colifeb24apps.com` → Laravel `public`).
3. Смоук: апартамент, контракт, LL, blocking; сверить логи.
4. Остановить cron `/var/www/alma`, архивировать/удалить `/var/www/alma` и старый nginx root.

### Рефакторинг при переносе (этап B)

- Классы + DI вместо `require_once` / global functions.
- Единый `AlmaApi` для realty + clients.
- Сохранить 1:1 бизнес-ветки (стадии, шеринг IDs, echo-skip, archive stages).
- Отдельно (не блочит cutover): обработка «archive usage» — release `external_id` + recreate (кейс 11702).

---

## Связанные документы

- [ALMA.md](ALMA.md) — кэш контактов и API `external-id` (уже в colife_db)
- Источник правды по полям/сценариям до переноса: `/var/www/alma/README.md`, `/var/www/alma/Documentation/`
