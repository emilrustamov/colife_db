# Модуль ChatApp (дополнительная разработка)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. ChatApp — отдельная интеграция мониторинга балансов диалогов, которая живёт рядом с БД, но к хранилищу Bitrix почти не относится.

Связь с Bitrix только через алерт в IM-чат при низком остатке лицензий.

## Зачем

Раз в день (2 раза по расписанию) забирает балансы диалогов из ChatApp API (аккаунты ОАЭ и Гонконг), пишет в локальную таблицу `dialog_balances` и при низком остатке шлёт сообщение в канал Bitrix IM. В тексте алерта флаг региона: 🇦🇪 / 🇭🇰.

## Flow

```
Schedule chatapp:collect (09:00 / 14:00 Europe/Moscow)
  → CollectCmd
  → CollectDialogs
  → for each account (ae, hk)
      → ChatAppApi (лицензии / балансы)
      → dialog_balances
      → ChatAppAlert (порог + флаг)
        → BitrixIm (im.message.add)
```

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Services/ChatAppApi.php` | Auth + API ChatApp |
| `app/Services/ChatAppAlert.php` | Порог и once/day алерт |
| `app/Services/BitrixIm.php` | Отправка в Bitrix IM (используется алертом) |
| `app/Jobs/CollectDialogs.php` | Сбор балансов |
| `app/Console/Commands/CollectCmd.php` | `chatapp:collect` |
| `app/Models/DialogBalance.php` | Локальное хранение балансов |
| `routes/console.php` | schedule `chatapp:collect` |
| `config/services.php` | `chatapp.*`, `bitrix_im.*` |
| `.env` / `.env.example` | `CHATAPP_*`, `BITRIX_IM_*` |

## Env

- `CHATAPP_EMAIL`, `CHATAPP_PASSWORD`, `CHATAPP_APP_ID` — аккаунт ОАЭ (🇦🇪)
- `CHATAPP_HK_EMAIL`, `CHATAPP_HK_PASSWORD`, `CHATAPP_HK_APP_ID` — отдельный кабинет Гонконг (🇭🇰)
- `CHATAPP_API_URL` (по умолчанию `https://api.chatapp.online`)
- `CHATAPP_ALERT_THRESHOLD` (по умолчанию `1000`)
- `CHATAPP_CABINET_LINE_URL`, `CHATAPP_HK_CABINET_LINE_URL` — ссылки в тексте алерта
- `BITRIX_IM_WEBHOOK`, `BITRIX_IM_DIALOG_ID` — куда слать уведомление
