# Модуль Open Lines Source (дополнительное решение)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. Этот модуль проставляет `SOURCE_ID` сделки на ГК по тексту первого входящего сообщения в открытой линии.

## Зачем

На [colifepacific.bitrix24.com](https://colifepacific.bitrix24.com/crm/deal/kanban/category/0/) лиды часто пишут в WhatsApp (Wazzup) по шаблонным ссылкам. Автоматика матчит текст → источник, пишет комментарий в таймлайн и ставит техполе, чтобы не обрабатывать сделку повторно.

## Скоуп

- Портал: только `colifepacific.bitrix24.com` (`B24_HK_PORTAL_DOMAIN`)
- Воронка: только `CATEGORY_ID = 0`
- Стадии: только «в работе» (`STAGE_SEMANTIC_ID = P`); success (`S`) и fail (`F`) — skip
- Событие: `ONOPENLINEMESSAGEADD`

## Пути локального приложения

| Поле в Bitrix | URL |
|---|---|
| Путь для первоначальной установки | `https://db.colifeb24apps.com/api/open-lines/install` |
| Приложение само завершает установку | да |
| Путь обработчика событий | `https://db.colifeb24apps.com/api/webhooks/bitrix/open-lines` |

Права приложения: **CRM**, **Открытые линии** (`crm`, `imopenlines`). Без скоупа `imopenlines` `event.bind` вернёт `ERROR_EVENT_NOT_FOUND`.

Можно расширить права существующего HK local-приложения (`B24_HK_CLIENT_ID`) или завести отдельное с install URL ниже. Токены пишутся в `bitrix_tokens` по `domain` — на один портал один OAuth-ряд.

## Flow

```
ONOPENLINEMESSAGEADD (HK)
  → POST /api/webhooks/bitrix/open-lines
  → OpenLineMessageJob
      → client inbound + text match
      → imopenlines.dialog.get → DEAL
      → category 0 + UF empty?
      → crm.deal.update (SOURCE_ID if empty + UF_CRM_1792743514293=Y)
      → crm.timeline.comment.add
```

Стоп-флаг: `UF_CRM_1792743514293` (`[тех поле] Источник проставлен автоматикой?`). Если заполнено — skip.

Если `SOURCE_ID` уже заполнен вручную, а текст совпал: техполе + комментарий без смены источника.

## Маппинг v1

| Источник | STATUS_ID | Паттерны |
|---|---|---|
| 28hse | `UC_JNWVQW` | `property listing from 28hse`, `我在 28Hse 見到`, `28hse.com/rent` |
| FB Groups | `UC_P3614V` | `facebook post`, `saw your facebook` |
| Website organic | `WEB` | `found your website`, `your website and would like` |
| WA - NovDec | `UC_TJK8ZS` | `book a room in advance` |

## Файлы модуля

| Файл | Роль |
|------|------|
| `app/Http/Controllers/Api/OpenLinesInstallController.php` | Установка + OAuth + queue event.bind |
| `app/Http/Controllers/Api/OpenLinesController.php` | Приём событий OL |
| `app/Jobs/RegisterOlEventsJob.php` | `event.bind` |
| `app/Jobs/OpenLineMessageJob.php` | Очередь обработки сообщения |
| `app/Services/OlDealSource.php` | Матч + update deal + timeline |
| `config/open_lines_source.php` | Domain, category, UF, rules |
| `resources/views/open_lines/install.blade.php` | Мастер установки |
| `routes/api.php` | `/api/open-lines/*`, webhook |

## Env

- `B24_HK_PORTAL_DOMAIN=colifepacific.bitrix24.com`
- `B24_HK_CLIENT_ID`, `B24_HK_CLIENT_SECRET`
- `BITRIX_OPEN_LINES_HANDLER_URL` (по умолчанию `{APP_URL}/api/webhooks/bitrix/open-lines`)
- `BITRIX_OPEN_LINES_APPLICATION_TOKEN` (**обязателен**; пустой = reject всех OL webhook / install/save)

## Логи

Канал `bitrix_open_lines` → `storage/logs/bitrix_open_lines.log`.

Ключевые маркеры: `OL_SOURCE_APPLIED`, `OL_SOURCE_SKIP_FLAG`, `OL_SOURCE_NO_MATCH`, `OL_SOURCE_NO_DEAL`, `OL_EVENT_BIND_RESULT`.

## Smoke

1. В карточке local-приложения на ГК включить права **CRM** + **Открытые линии**, путь установки — URL выше, переустановить приложение.
2. В логах: `OL_INSTALL_SAVE_OK`, `OL_EVENT_BIND_RESULT` с `result: true` (не `ERROR_EVENT_NOT_FOUND`).
3. Написать в WA тестовое сообщение с текстом `28hse` в сделку category 0.
4. Ожидание: `SOURCE_ID=UC_JNWVQW`, техполе `Y`, комментарий в таймлайне; в логах `OL_SOURCE_APPLIED`.
5. Второе сообщение — `OL_SOURCE_SKIP_FLAG`.

> Документация Bitrix: `OnOpenLineMessageAdd` часто приходит только для коннектора, который зарегистрировало само приложение. Wazzup — сторонний коннектор: если после bind с валидным скоупом события всё равно не приходят, нужен fallback (исходящий вебхук / Wazzup API) — вне текущего smoke.
