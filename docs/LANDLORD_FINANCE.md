# Модуль Landlord finance (дополнительная разработка)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. Этот модуль читает Google Sheets отчёты собственников и пушит месячные пакеты в Alma External API.

Замена Google Apps Script на серверный collector.

Google Sheets / Drive / Apps Script — только **чтение**. Пустые месяцы (без доходов/расходов) тоже отправляются в Alma при collect — иначе текущий месяц не появляется.

## Реестр vs отправка в Alma

| Команда | Что делает |
|---------|------------|
| `landlord-finance:sync-registry` | **Только наша БД** (`landlord_finance_sheets`). Finance в Alma **не пушит**. ID: E2/E3/E4 или Apps Script `setConfig` / `setProperties`. |
| `landlord-finance:verify-alma-ids` | Сверка `landlord_id`/`apart_id` из реестра с Alma (GET client/unit by external_id). Finance **не пушит**. |
| `landlord-finance:collect` | Читает Sheets и **пушит** в Alma `landlord_property_finance`. |
| `landlord-finance:import-reports` | Регистрация + collect (пуш). Для массового наполнения реестра без пуша — `sync-registry`. |

`sync-registry` пишет в отчёт: `storage/app/google/landlord_finance_registry_sync.json`.

Правила реестра:

- сводки `Прибыль…` / `Аренда` / `All apartments` — **не добавляются** в БД;
- вкладка года **2026** (ссылка на 2025 → переключение на парную 2026);
- ID: `E2`=apart, `E3`=landlord, `E4`=второй соб (`landlord_id_2`); если ячеек нет — попытка прочитать bound Apps Script source (`APART_ID` / `UNIT_ID`);
- `is_external` — файл вне org / без права edit (Drive metadata).

## На что ориентируемся

Источник правды для **денежного** синка — **Alma External API**.

В `landlord_property_finance` уходят **external_id**:

- `landlord_id` = Alma client.`external_id`
- `unit_id` (поле Alma API) = наш `apart_id` = Alma unit.`external_id` (= Bitrix apartment id)

Резолв ids при collect:

1. Пара в реестре (кэш)
2. Только `apart_id` → active owner contract в Alma → client.`external_id`
3. Ячейки `E2`/`E3` — bootstrap

### Сверка с Bitrix SPA 148

Bitrix не источник правды, а **контроль**:

- по `apart_id` ищем контракты LL (`ufCrm10_1693823301`)
- сравниваем `contactId` с Alma `landlord_id`
- сравниваем apartment id с Alma `apart_id`
- расхождения → лог `landlord_finance` + Telegram

```bash
php artisan landlord-finance:reconcile
php artisan landlord-finance:reconcile --id=1 --no-notify
```

Schedule: `06:30` Europe/Moscow.

## Flow

```
landlord-finance:sync-registry
  → ссылки Reports_2026 → вкладка 2026 → E2/E3/E4 или Apps Script
  → landlord_finance_sheets (без Alma)

Schedule landlord-finance:collect (06:00 Europe/Moscow)
  → CollectLandlordFinance
      → GoogleSheetsApi + parser
      → AlmaApi::pushLandlordPropertyFinance
  → при ошибке → CollectFailureAlert
```

## Реестр

Таблица `landlord_finance_sheets`:

| Поле | Смысл |
|------|--------|
| `spreadsheet_id` / `sheet_name` | Google spreadsheet + вкладка |
| `source_url` | исходная ссылка из индекса |
| `landlord_id` / `landlord_id_2` / `apart_id` | Alma external_id (apart = unit.external_id; второй соб из E4) |
| `id_source` | `cells` \| `script` |
| `is_external` | чужой / обычно нельзя редактировать |
| `bitrix_ownership_id` | SPA 148 id |
| `layout` | `year_tabs` \| `unit_tabs` \| `apt_tabs` |

Уникальность: `(spreadsheet_id, sheet_name)`.

### Форматы книг

1. **`year_tabs`** — вкладки `2023`/`2026`, один юнит на книгу.
2. **`unit_tabs`** — вкладки `2026 Lake View, apt. 2801`.
3. **`apt_tabs`** — вкладки квартир в книге собственника; сводки Прибыль/Аренда игнорируются.

## OAuth scopes

- `spreadsheets.readonly`
- `drive.readonly` (метаданные + поиск bound script; в GCP должен быть **включён Drive API**)
- `script.projects.readonly` (чтение исходников Apps Script)

```bash
php artisan landlord-finance:google-auth
```

## Env

- `LANDLORD_FINANCE_AUTH` — `oauth` (по умолчанию) или `sa`
- `LANDLORD_FINANCE_API_KEY` — fallback: `ALMA_API_KEY`
- `LANDLORD_FINANCE_OAUTH_CLIENT_JSON` / `LANDLORD_FINANCE_OAUTH_TOKEN_JSON`
- `LANDLORD_FINANCE_GOOGLE_CREDENTIALS` — только при `auth=sa`
- `LANDLORD_FINANCE_YEARS` — фильтр годов (по умолчанию `2026`)

## Команды

```bash
php artisan migrate
php artisan landlord-finance:google-auth
php artisan landlord-finance:sync-registry --year=2026
php artisan landlord-finance:verify-alma-ids
php artisan landlord-finance:collect --dry-run
php artisan landlord-finance:collect --year=2026
php artisan landlord-finance:collect --id=1 --year=2026
```

## Логи

Канал `landlord_finance` → `storage/logs/landlord_finance-YYYY-MM-DD.log`.
