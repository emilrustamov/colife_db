# Ручные OPS-команды

Команды ниже **не** в `routes/console.php`. Запускаются вручную при обслуживании.

| Команда | Назначение |
|---------|------------|
| `timeline:backfill-*` | Дозаполнение timeline/activity из Bitrix |
| `timeline:cleanup-*` | Очистка старых timeline-записей |
| `bitrix:cleanup-old-rent-folders` | Удаление устаревших rent-папок на диске |
| `bitrix:ensure-repair-folders` | Создание repair-папок апартаментов |
| `bitrix:sync-repair-disk` | Точечная синхронизация repair disk |
| `bitrix:sync-disk` | Полный pull диска (обычно event-driven через `/api/disk/*`) |
| `telegram:setup` | Разовая настройка webhook бота |
| `alma-bridge:upsert` | Ручной upsert сущности Bridge |
| `bitrix:sync-spa {entity}` | Общий SPA-sync (`apartments`, `units`, `apartment-ownerships`, `unit-stays`); старые `bitrix:sync-*` — aliases |

## Identifiers

- Core tables (`apartments`, `units`, `contacts`, …): local UUID `id` + `bitrix_id`.
- Snapshots / balances: raw Bitrix ids (`bitrix_units_snapshot.apart_id`, `client_balances.apartment_bitrix_id`). Не джойнить к `apartments.id`.
- Reference directories `buildings`, `metro_stations`, `apartment_types`: **static/manual** (нет scheduled sync writer); правятся через Directory / seed.
