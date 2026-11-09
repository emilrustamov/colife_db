# Модуль Wikibot (дополнительная разработка)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. Wikibot — отдельный мониторинг тарифа/кредитов и денежного баланса аккаунта.

Связь с Bitrix только через алерт в IM-чат и задачу в IT Dubai Tracker при низком остатке.

Публичный API: [GET /api/bot/billing](https://wikibot.pro/docs/api/billing/get-billing).

## Зачем

Раз в день забирает биллинг Wikibot. Смотрим **`money.totalCredits`** (кредиты тарифа + то, что можно купить на балансе) — иначе при покупке пакетов с запасом видно только текущий пакет (`credits`). Поле `money` приходит только если у API-ключа включены scopes **`manage`** и **«Баланс аккаунта» (`billing`)**.

Алерт в Bitrix IM (не чаще одного раза в сутки), только если **оба** условия:

- `credits` (кредиты текущего тарифа) ≤ порога (`WIKIBOT_ALERT_THRESHOLD`, по умолчанию `6000`), и
- `money.balance` ≤ 0 (рублей на автопокупку пакета уже нет).

Если на балансе ещё есть ₽ — алерт не шлём: их хватит на автопокупку кредитов.

## Flow

```
Schedule wikibot:collect (09:00 Europe/Moscow)
  → WikibotCollectCmd
  → CollectWikibotBilling
  → WikibotApi (GET /bot/billing)
  → WikibotAlert (порог / canQuery, once/day)
    → DailyAlert → AlertTask + BitrixIm
  → при ошибке → CollectFailureAlert (раз в сутки)
```

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Services/WikibotApi.php` | REST billing |
| `app/Services/WikibotAlert.php` | Порог и once/day алерт |
| `app/Jobs/CollectWikibotBilling.php` | Сбор |
| `app/Console/Commands/WikibotCollectCmd.php` | `wikibot:collect` |
| `routes/console.php` | schedule `wikibot:collect` |
| `config/services.php` | `wikibot.*`, SPA `api_owned_services` 11370 |
| `.env` / `.env.example` | `WIKIBOT_*`, `BITRIX_IM_*` |

## Env

- `WIKIBOT_API_KEY` — ключ из Settings → API Keys (Authorization as-is, без Bearer)
- `WIKIBOT_API_URL` — базовый URL (по умолчанию `https://api.wikibot.pro/api`)
- `WIKIBOT_ALERT_THRESHOLD` — порог по `credits` текущего тарифа (по умолчанию `6000`); срабатывает только вместе с `money.balance` ≤ 0
- `WIKIBOT_CABINET_URL` — ссылка в алерте
- `WIKIBOT_PORTAL` — код портала в notice (по умолчанию `AE`)
- `WIKIBOT_PAY_METHOD` — способ оплаты в notice (по умолчанию `Карта`)
- `BITRIX_IM_WEBHOOK`, `BITRIX_IM_DIALOG_ID` — канал уведомлений
