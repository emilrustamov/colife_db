# OPS-команды (не в schedule)

> Ручные / one-off команды. **Не** часть ежедневной синхронизации. Не удалять без проверки — могут понадобиться для обслуживания.

| Команда | Файл | Назначение |
|---------|------|------------|
| `timeline:cleanup-bitrix-contact-noise` | `TimelineCleanupCommand` | Чистка шумных `bitrix.contact.updated` в activity_logs (`--dry-run`) |
| `timeline:backfill-bitrix-contact-old-values` | `TimelineBackfillCommand` | Backfill `old_values` у timeline (`--dry-run`) |
| `bitrix:cleanup-old-rent-folders` | `CleanupRentFoldersCommand` | Удаление старых папок Rent на диске (по умолчанию dry-run, `--execute`) |
| `bitrix:sync-disk` | `SyncDiskCommand` | Ручной sync диска; обычный путь — API `/api/disk/*` + job |

В `php artisan list` у них в description есть префикс `[OPS ...]`.
