# Модуль оплаты сервисов из Bitrix (дополнительная разработка)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. Этот модуль читает SPA «Оплата сервисов» и шлёт алерты в IM. К хранилищу Bitrix почти не относится.

SPA: [Оплата сервисов](https://colifeae.bitrix24.eu/page/chatapp_linessimcards/oplata_servisov/type/1094/list/category/74/), `entityTypeId=1094`, воронка `74`.

ChatApp WA-линии, Twilio и VDSina собираются своими API — они приоритетнее SPA. Из SPA берутся только сервисы без API-сборщика и **ChatApp другие линии** (не WA): **CallGear, REG.RU, Miro, ChatApp**. Карточки Twilio / VDSina в SPA игнорируются.

## Зачем

Раз в день читает карточки SPA и шлёт сообщение в канал Bitrix IM **только в календарный день уведомления** (`ufCrm58Notificication`). После оплаты карточка сама сдвигается и меняет дату — повторных алертов нет. Неактивные (`DT1094_74:FAIL`) пропускаются. Способ оплаты берётся из `ufCrm58_1787301535197` (Карта / Счёт) и добавляется в сообщение, если поле заполнено.

## Flow

```
Schedule payments:collect (09:00 Europe/Moscow)
  → ServicePayCollectCmd
  → CollectServicePay
  → ServicePayApi (crm.item.list, type 1094 / category 74)
  → ServicePayAlert (notify date = today)
    → BitrixIm (im.message.add)
```

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Services/ServicePayApi.php` | Чтение SPA |
| `app/Services/ServicePayAlert.php` | Алерт по дате уведомления |
| `app/Services/BitrixIm.php` | Отправка в Bitrix IM |
| `app/Jobs/CollectServicePay.php` | Сбор |
| `app/Console/Commands/ServicePayCollectCmd.php` | `payments:collect` |
| `routes/console.php` | schedule `payments:collect` |
| `config/services.php` | `service_payments.*` |

## Env

Используется тот же вебхук, что и остальной Bitrix REST: `BITRIX_WEBHOOK` / `BITRIX_UNITS_WEBHOOK`, плюс `BITRIX_IM_*` для канала.
