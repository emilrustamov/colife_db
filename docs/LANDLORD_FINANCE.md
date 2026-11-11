# Модуль Landlord finance (дополнительная разработка)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. Этот модуль читает Google Sheets отчёты собственников и пушит месячные пакеты в Alma External API.

Замена Google Apps Script на серверный collector.

Google Sheets только **чтение** (`spreadsheets.readonly`): запись/правка отчётов из модуля невозможна. Пустые месяцы (без доходов/расходов) тоже отправляются в Alma — иначе текущий месяц не появляется.

## На что ориентируемся

Источник правды для синка — **Alma External API**.

В `landlord_property_finance` уходят **external_id**:

- `landlord_id` = Alma client.`external_id`
- `unit_id` = Alma unit.`external_id`

Резолв ids:

1. Пара в реестре (кэш)
2. Только `unit_id` → active owner contract в Alma → client.`external_id`
3. Ячейки `E2`/`E3` — разовый bootstrap

### Сверка с Bitrix SPA 148

Bitrix не источник правды, а **контроль**:

- по `unit_id` ищем контракты LL (`ufCrm10_1693823301`)
- сравниваем `contactId` с Alma `landlord_id`
- сравниваем apartment id с Alma `unit_id`
- расхождения → лог `landlord_finance` + Telegram

```bash
php artisan landlord-finance:reconcile
php artisan landlord-finance:reconcile --id=1 --no-notify
```

Schedule: `06:30` Europe/Moscow.

## Flow

```
Schedule landlord-finance:collect (06:00 Europe/Moscow)
  → LandlordFinanceCollectCmd
  → CollectLandlordFinance
      → resolve Alma ids (Bitrix 148 → Alma, иначе one-time cells)
      → GoogleSheetsApi
      → LandlordFinanceSheetParser
      → AlmaApi::pushLandlordPropertyFinance
  → при ошибке → CollectFailureAlert
```

## Реестр

Таблица `landlord_finance_sheets`:

| Поле | Смысл |
|------|--------|
| `spreadsheet_id` | Google spreadsheet |
| `sheet_name` | `''` = вся книга year-tabs; иначе конкретный unit-tab |
| `bitrix_ownership_id` | SPA 148 id (предпочтительно) |
| `landlord_id` / `unit_id` | Alma ids (кэш) |
| `layout` | `auto` \| `year_tabs` \| `unit_tabs` |

Уникальность: `(spreadsheet_id, sheet_name)`.

### Форматы книг

1. **`year_tabs`** — вкладки `2023`/`2026`, один юнит на книгу. Заполняем `bitrix_ownership_id` (или уже известные Alma ids).
2. **`unit_tabs`** — вкладки `2026 Lake View, apt. 2801`. Книга-шаблон (`sheet_name=''`) при первом прогоне создаёт строки на каждый лист; id один раз берутся из ячеек/Bitrix и сохраняются.

## Env

- `LANDLORD_FINANCE_AUTH` — `oauth` (по умолчанию) или `sa`
- `LANDLORD_FINANCE_API_KEY` — fallback: `ALMA_API_KEY`
- `LANDLORD_FINANCE_OAUTH_CLIENT_JSON` / `LANDLORD_FINANCE_OAUTH_TOKEN_JSON`
- `LANDLORD_FINANCE_GOOGLE_CREDENTIALS` — только при `auth=sa`
- `LANDLORD_FINANCE_YEARS` — опциональный фильтр `2025,2026`

Timeout, delay, ячейки E2/E3 и entity type 148 — дефолты в `config/services.php`.

## Команды

```bash
php artisan migrate
php artisan landlord-finance:google-auth
php artisan landlord-finance:collect --dry-run
php artisan landlord-finance:collect --year=2026
php artisan landlord-finance:collect --id=1 --year=2026
```

Привязка к Bitrix:

```php
$sheet = LandlordFinanceSheet::find(1);
$sheet->bitrix_ownership_id = 3418;
$sheet->landlord_id = null; // принудительно перерезолвить из Bitrix
$sheet->unit_id = null;
$sheet->save();
```

## Логи

Канал `landlord_finance` → `storage/logs/landlord_finance-YYYY-MM-DD.log`.
