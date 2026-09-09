# Модуль REG.RU (дополнительная разработка)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. REG.RU — отдельный мониторинг сроков доменов, который живёт рядом с БД, но к хранилищу Bitrix почти не относится.

Связь с Bitrix только через алерт в IM-чат, когда домен скоро истекает. Баланс аккаунта не собирается.

Публичный API: [Рег.API 2](https://www.reg.ru/reseller/api2doc), метод `service/get_list`.

## Зачем

Раз в день забирает список доменов REG.RU (`POST /service/get_list`, `servtype=domain`). Если до истечения **30 дней и меньше**, шлёт сообщение в канал Bitrix IM (не чаще одного раза в сутки на домен).

## Flow

```
Schedule regru:collect (09:00 Europe/Moscow)
  → RegruCollectCmd
  → CollectRegruDomains
  → RegruApi (service/get_list)
  → RegruAlert (≤ 30 дней, once/day на домен)
    → BitrixIm (im.message.add)
```

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Services/RegruApi.php` | REST API доменов |
| `app/Services/RegruAlert.php` | Порог по дням и once/day алерт |
| `app/Services/BitrixIm.php` | Отправка в Bitrix IM |
| `app/Jobs/CollectRegruDomains.php` | Сбор доменов |
| `app/Console/Commands/RegruCollectCmd.php` | `regru:collect` |
| `routes/console.php` | schedule `regru:collect` |
| `config/services.php` | `regru.*`, `bitrix_im.*` |
| `.env` / `.env.example` | `REGRU_*`, `BITRIX_IM_*` |

## Env

- `REGRU_USERNAME` — логин REG.RU
- `REGRU_PASSWORD` — пароль для API (лучше альтернативный, не основной)
- `REGRU_API_URL` — базовый URL (по умолчанию `https://api.reg.ru/api/regru2`)
- `REGRU_ALERT_DAYS` — за сколько дней до окончания слать алерт (по умолчанию `30`)
- `REGRU_CABINET_URL` — ссылка в алерте (по умолчанию `https://www.reg.ru/user/account/#/domains`)
- `BITRIX_IM_WEBHOOK`, `BITRIX_IM_DIALOG_ID` — куда слать уведомление

В [настройках API](https://www.reg.ru/user/account/#/settings/api) нужно разрешить IP сервера.
