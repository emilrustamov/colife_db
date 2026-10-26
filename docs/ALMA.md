# Модуль Alma contacts (дополнительная разработка)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. Alma contacts — локальный кэш клиентов Alma и API для смены `external_id` (Bitrix contact id) по email.

Не путать с **Alma Bridge** (апартаменты / контракты B24→Alma) — см. [ALMA_BRIDGE.md](ALMA_BRIDGE.md). Bridge пока на `/var/www/alma`, в Laravel не перенесён.

В Alma поиск клиента доступен только по внутреннему `id` или `external_id`, поэтому email-индекс хранится у нас.

## Зачем

1. Раз в 2 часа выгружает список клиентов из Alma External API и обновляет в БД только изменившиеся записи (`alma_id`, `bitrix_id`, `email`).
2. Принимает API-запрос `email` + `external_id`: находит Alma-клиента по email в локальном кэше и, если `external_id` изменился, вызывает Alma PATCH.

## Flow

```
Schedule alma:collect (every 2 hours, Europe/Moscow)
  → AlmaCollectCmd
  → CollectAlmaContacts
  → AlmaApi (GET users/clients/)
  → alma_contacts (только изменённые строки)

POST /api/alma/contacts/external-id (X-Api-Key)
  → AlmaContactController
  → AlmaContactService (поиск по email)
  → AlmaApi (PATCH users/clients/{alma_id}/) при изменении
  → обновление bitrix_id в alma_contacts
```

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Services/AlmaApi.php` | Alma External API |
| `app/Services/AlmaContactService.php` | Смена external_id по email |
| `app/Jobs/CollectAlmaContacts.php` | Синхронизация кэша |
| `app/Console/Commands/AlmaCollectCmd.php` | `alma:collect` |
| `app/Models/AlmaContact.php` | Модель `alma_contacts` |
| `app/Http/Controllers/Api/AlmaContactController.php` | HTTP API |
| `app/Http/Requests/UpdateAlmaContactExternalIdRequest.php` | Валидация |
| `routes/api.php` | `POST /api/alma/contacts/external-id` |
| `routes/console.php` | schedule `alma:collect` |
| `config/services.php` | `alma.*` |
| `.env` / `.env.example` | `ALMA_*`, `CLIENT_BALANCE_API_KEY` |

## API

`POST /api/alma/contacts/external-id`

Заголовок: `X-Api-Key: <CLIENT_BALANCE_API_KEY>`

Тело:

```json
{
  "email": "user@example.com",
  "external_id": "12345"
}
```

Ответ при успехе:

```json
{
  "success": true,
  "changed": true,
  "message": "external_id updated successfully.",
  "data": {
    "alma_id": 851,
    "bitrix_id": "12345",
    "email": "user@example.com"
  }
}
```

`changed: false` — в Alma уже был такой же `external_id`, PATCH не вызывался.

## Env

- `ALMA_API_URL` — базовый URL External API (со слешем, например `https://…/external_api/`)
- `ALMA_API_KEY` — ключ Alma (`Api-Key`)
- `ALMA_API_TIMEOUT` — таймаут HTTP в секундах (по умолчанию `120`)
- `CLIENT_BALANCE_API_KEY` — ключ для нашего API (`X-Api-Key`)

## Логи

Канал `alma_contacts` → `storage/logs/alma_contacts-YYYY-MM-DD.log` (30 дней):

- каждый вызов `POST /api/alma/contacts/external-id` (ok / rejected / failed)
- `alma:collect` (finished / failed)

## Команды

```bash
php artisan alma:collect
php artisan migrate
```
