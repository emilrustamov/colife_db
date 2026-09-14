# Модуль Cleaning Report (дополнительное решение)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. Cleaning Report — локальное приложение для турецкого портала `colife.bitrix24.com.tr`.

## Зачем

Форма отчёта по уборке во фрейме Bitrix24: выбор апартамента/юнита, клинера, чеклиста и фото. После сабмита — комментарий в таймлайне юнита, задача Cleaning и опционально задача на ремонт.

## Пути локального приложения

| Поле в Bitrix | URL |
|---|---|
| Путь для первоначальной установки | `https://db.colifeb24apps.com/api/cleaning/install` |
| Приложение само завершает установку | да |
| Путь обработчика | `https://db.colifeb24apps.com/api/cleaning` |

Права приложения: **CRM**, **Диск**, **Задачи**, **Пользователи**.

Устанавливать нужно под системным пользователем **71857** — от его OAuth-токена пишутся комментарии и задачи.

## Flow

```
Bitrix local app install (user 71857)
  → POST/GET /api/cleaning/install
  → bitrix_tokens (TR domain)
  → BX24.installFinish()

Staff opens /api/cleaning
  → BX24 crm.item.list apartments (165): pages of 50 via `start`/`next`
  → filter out FAIL stages; units by UF on select
  → POST /api/cleaning/submit
      → disk upload
      → crm.timeline.comment.add on unit (as 71857)
      → tasks.task.add group 13 (assignee 62997, participant 110103)
      → optional tasks.task.add group 27 bound to apartment
```

Каталог апартов **не** хранится в локальной БД (TR). В БД есть таблица `portals` и `portal_id` на `apartments`/`units` для AE-синка основного проекта.

## Пагинация BX24

`crm.item.list` отдаёт максимум 50 записей и поле `next`. В **BX24.js** параметр `start` из `params` **удаляется** при отправке; следующая страница только через `result.more()` → `result.next()` (SDK сам пишет `query.start`). Ручной `callMethod(..., { start: 50 })` всегда возвращает первую страницу.

## Пользователи и проекты

| Роль | ID |
|---|---|
| Автор комментария / постановщик | 71857 |
| Assignee (Sergey Saryyev) | 62997 |
| Participant (M.Kaan AKBAŞ) | 110103 |
| Проект cleaning / maintenance | 13 |
| Проект housemaster | 27 |

Название основной задачи: `[Cleaning Type]_ [Date]_ [Apartment Number]`  
Пример: `Deep Cleaning_ 09.09.2026_ A-201`  
Deadline: +2 дня.

Клинеры (статика): Nursel, Lena, Fatma, Oksana, İlvira.

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Http/Controllers/Api/CleaningInstallController.php` | Установка + сохранение OAuth |
| `app/Http/Controllers/Api/CleaningFormController.php` | UI формы |
| `app/Http/Controllers/Api/CleaningSubmitController.php` | Приём сабмита |
| `app/Services/CleaningSubmitService.php` | Диск + задачи + комментарий |
| `resources/views/cleaning/install.blade.php` | Мастер установки |
| `resources/views/cleaning/form.blade.php` | Форма |
| `routes/api.php` | `/api/cleaning/*` |
| `config/services.php` | `b24_tr.*`, `cleaning.*` |

Фото сохраняются на диск: [Cleaning](https://colife.bitrix24.com.tr/docs/path/Cleaning/) → `YYYY` → `MM` → `DD_ApartmentTitle`.

## Env

- `B24_TR_PORTAL_DOMAIN` (по умолчанию `colife.bitrix24.com.tr`)
- `B24_TR_CLIENT_ID`, `B24_TR_CLIENT_SECRET`, `B24_TR_REDIRECT_URI`
- `CLEANING_DISK_FOLDER_ID` (по умолчанию `396621` — папка Cleaning в общем диске)
- Опционально: `CLEANING_SYSTEM_USER_ID`, `CLEANING_ASSIGNEE_ID`, `CLEANING_PARTICIPANT_ID`, `CLEANING_GROUP_ID`, `CLEANING_HOUSEMASTER_GROUP_ID`

## Логи

Канал `bitrix_cleaning` → `storage/logs/bitrix_cleaning-*.log`.
