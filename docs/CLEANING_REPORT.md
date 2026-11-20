# Модуль Cleaning Report (дополнительное решение)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. Cleaning Report — локальное приложение для турецкого портала `colife.bitrix24.com.tr`.

## Зачем

Форма отчёта по уборке во фрейме Bitrix24: выбор апартамента/юнита, клинера, чеклиста и фото. После сабмита — комментарий в таймлайне; задача housemaster только если отмечен Needs repair.

## Пути локального приложения

| Поле в Bitrix | URL |
|---|---|
| Путь для первоначальной установки | `https://db.colifeb24apps.com/api/cleaning/install` |
| Приложение само завершает установку | да |
| Путь обработчика | `https://db.colifeb24apps.com/api/cleaning` |

Права приложения: **CRM**, **Диск**, **Задачи**, **Пользователи**, **Списки** (для daily sync клинеров).

После добавления права «Списки» — переустановить приложение (install URL), иначе `lists.*` вернёт `insufficient_scope`. Комментарии в таймлайне пишет владелец OAuth-токена (кто установил приложение).

## Flow

```
Bitrix local app install
  → POST/GET /api/cleaning/install
  → bitrix_tokens (TR domain)
  → BX24.installFinish()

Staff opens /api/cleaning
  → BX24 crm.item.list apartments (165): pages of 50 via `start`/`next`
  → filter out FAIL stages; units by UF on select
  → tabs: New report | History (default list from local `cleaning_reports`; optional apartment filter)
  → backfill: `cleaning:sync-history`
  → POST /api/cleaning/submit
      → disk upload
      → crm.timeline.comment.add on apartment (+ unit unless Common area)
      → only if Needs repair: tasks.task.add group 27 (housemaster)
```

Каталог апартов **не** хранится в локальной БД (TR). В БД есть таблица `portals` и `portal_id` на `apartments`/`units` для AE-синка основного проекта.

## Пагинация BX24

`crm.item.list` отдаёт максимум 50 записей и поле `next`. В **BX24.js** параметр `start` из `params` **удаляется** при отправке; следующая страница только через `result.more()` → `result.next()` (SDK сам пишет `query.start`). Ручной `callMethod(..., { start: 50 })` всегда возвращает первую страницу.

## Пользователи и проекты

| Роль | ID |
|---|---|
| CREATED_BY на repair-задаче | 71857 |
| Assignee (Sergey Saryyev) | 62997 |
| Participant / task chat (Ilvira Yetişgin) | 63019 |
| History delete (Emil Rustamov, M.Kaan AKBAŞ) | 70855, 110103 — удаляет local row + apartment/unit timeline + repair task + disk files |
| Проект housemaster (repair task) | 27 |

Автозадача создаётся **только** при `Needs repair`.  
Название из SPA-поля **Apartment number** (`ufCrm5_1692857555592`) + тип уборки:  
Пример: `64 - Deep Cleaning`  
Deadline: +2 дня.

Клинеры: универсальный список TR [`87`](https://colife.bitrix24.com.tr/company/lists/87/view/0/) → таблица `cleaning_staff`.  
- При открытии формы: BX24 `lists.element.get` → `POST /api/cleaning/staff-sync` (права пользователя, работает без scope Lists у приложения).  
- Hourly: `cleaning:sync-staff` (нужен scope **Списки** у OAuth-приложения).  
Форма/submit читают локальные имена; пока таблица пуста — fallback `config/services.php` → `cleaning.staff`.  
Элемент списка = имя в поле **Название** (`NAME`).

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Http/Controllers/Api/CleaningInstallController.php` | Установка + сохранение OAuth |
| `app/Http/Controllers/Api/CleaningFormController.php` | UI формы |
| `app/Http/Controllers/Api/CleaningSubmitController.php` | Приём сабмита |
| `app/Services/CleaningSubmitService.php` | Диск + задачи + комментарий |
| `app/Services/CleaningStaffSync.php` | Sync списка клинеров |
| `app/Http/Controllers/Api/CleaningStaffSyncController.php` | `POST /api/cleaning/staff-sync` |
| `app/Console/Commands/SyncCleaningStaffCommand.php` | `cleaning:sync-staff` |
| `app/Models/CleaningStaff.php` | Локальный кэш staff |
| `resources/views/cleaning/install.blade.php` | Мастер установки |
| `resources/views/cleaning/form.blade.php` | Форма |
| `routes/api.php` | `/api/cleaning/*` |
| `config/services.php` | `b24_tr.*`, `cleaning.*` |

Фото сохраняются на диск: [Cleaning](https://colife.bitrix24.com.tr/docs/path/Cleaning/) → `YYYY` → `MM` → `DD_ApartmentTitle`.

## Env

- `B24_TR_PORTAL_DOMAIN` (по умолчанию `colife.bitrix24.com.tr`)
- `B24_TR_CLIENT_ID`, `B24_TR_CLIENT_SECRET`, `B24_TR_REDIRECT_URI`
- `CLEANING_DISK_FOLDER_ID` (по умолчанию `396621` — папка Cleaning в общем диске)
- `CLEANING_STAFF_LIST_IBLOCK_ID` (по умолчанию `87`)
- Опционально: `CLEANING_OBSERVER_IDS`, `CLEANING_DEADLINE_DAYS`

User/group/entity IDs и поле units — в `config/services.php` → `cleaning.*` (не в `.env`).

## Логи и алерты

Канал `bitrix_cleaning` → `storage/logs/bitrix_cleaning-*.log`.

Auth submit: `auth[domain]` + живой `auth[access_token]` из `BX24.getAuth()` (сервер проверяет через `user.current` на TR).

Telegram (`CleaningAlert`): submit 500, form 403. OAuth TR — через `telegram:health` → `/api/cleaning/install`.
