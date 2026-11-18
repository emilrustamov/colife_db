# Модуль Prices / календарь цен (дополнительная разработка)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. Prices — occupancy/revenue отчёт по unit stays (краткосрок/долгосрок) с UI на `prices.colifeb24apps.com`.

Не путать с OL Bot catalog prices / Wikibot «тариф» / Landlord finance. Источник данных — уже синхронизированные SPA **144 / 167 / 183** (`apartments`, `units`, `unit_stays`), не отдельный CRest-sync.

## Зачем

1. Считает помесячные occupied days / revenue / avg day rate из `unit_stays`.
2. Отдаёт публичный отчёт и Excel на домене prices (без логина админки).
3. Заменил legacy `/var/www/prices` (папка удалена).

## Flow

```
Schedule bitrix:sync-apartments / units / unit-stays (daily)
  → districts (справочник) + apartments.district_id
  → contract_types (справочник bucket краткосрок/долгосрок)
  → unit_stays
    (buildings / apartment_types — существующие directories)

Schedule prices:calculate (23:42 Europe/Moscow)
  → UnitStayQuery + MonthlyReportCalculator
  → price_monthly_reports

Browser https://prices.colifeb24apps.com/
  → ReportController (Vue UI)
  → GET/POST /api?action=… (ReportQueryService)
  → price_monthly_reports + unit_stays drill-down / export

Bitrix local app reinstall
  → GET/POST /install (BX24.installFinish)
```

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Services/Prices/MonthlyReportCalculator.php` | Пересчёт агрегатов |
| `app/Services/Prices/ReportQueryService.php` | API отчёта / фильтры / export |
| `app/Services/Prices/XlsxExporter.php` | XLSX |
| `app/Support/Prices/ContractTypeMapper.php` | Единый справочник подтипов → краткосрок/долгосрок |
| `app/Support/DistrictNormalizer.php` | Алиасы районов (core sync, не Prices-only) |
| `app/Services/Prices/UnitStayQuery.php` | Общий scope валидных stays |
| `app/Services/Prices/ReportFilters.php` | Парсинг фильтров API |
| `app/Jobs/CalculatePriceReports.php` | Фоновый пересчёт |
| `app/Console/Commands/PricesCalculateCmd.php` | `prices:calculate` |
| `app/Http/Controllers/Prices/*` | UI / API / install |
| `routes/prices.php` | Domain routes |
| `resources/views/prices/*` | Blade + Vue CDN UI |
| `public/prices/*` | `app.css`, `vue.global.prod.js` |
| `config/services.php` | `prices.*` |

## Config

`config/services.php` → `prices.*` (без `.env`, редко меняется):

- `domain` — `prices.colifeb24apps.com`
- `contract_url` — ссылка на карточку контракта в B24
- `cache_ttl` — 300
- `export_max_years` — 5

Безопасность: на `/api` и `/install` снят CSRF (и session на `/api`) через `withoutMiddleware`; throttle `prices-api`; ошибки клиенту без stack в prod. Пересчёт только через `prices:calculate` / schedule (лок в `rebuild()`, HTTP sync нет).

## Команды

| Команда | Назначение |
|---------|------------|
| `php artisan prices:calculate` | Полный пересчёт `price_monthly_reports` |
| `php artisan prices:calculate --queue` | То же через очередь |
| `php artisan bitrix:sync-apartments` | Подтянуть `district_id` (`ufCrm6District`) |

## API actions (`/api?action=`)

`meta` (years / districts / buildings / contract & apartment types / month labels), `units`, `stats`, `month_contracts`, `report`, `export`.

Справочники в админке (`/directories`): **Districts**, **Contract Types**.  
Prices читает `districts` / `contract_types` из БД (кеш 5 мин), не ходит в Bitrix за фильтрами.  
Типы апартаментов — из `apartment_types`.

## Cutover

Сделано на сервере:

1. nginx `prices.colifeb24apps.com` → `root /var/www/colife_db/public` (vhost-файл тоже `.com`, не `.ru`)
2. legacy cron `php /var/www/prices/sync.php` отключён; считает Laravel `prices:calculate`
3. `/var/www/prices` удалён
4. совместимость Bitrix URL: `/install.php` → Laravel `/install`, handler `/index.php` = отчёт
5. публичные prices-роуты без session/CSRF/InertiaShare

### Переустановка локального приложения в Bitrix24

Тип: **Серверное / Статичное**, «Приложение само завершает установку».

| Поле | Значение |
|------|----------|
| Путь для первоначальной установки | `https://prices.colifeb24apps.com/install.php` |
| Путь вашего обработчика | `https://prices.colifeb24apps.com/index.php` |

`client_id` / `client_secret` только в карточке Bitrix (в colife_db не храним — отчёт из уже синхронизированной БД). Сохранить → переустановить из портала → должен вызваться `BX24.installFinish`.

Проверка:

```bash
curl -sS 'https://prices.colifeb24apps.com/api?action=meta' | head
curl -sS 'https://prices.colifeb24apps.com/install.php' | head
```

Домен только `prices.colifeb24apps.com` (не `.ru`).
