# Модуль Open Lines Source (дополнительное решение)

> **Не основная цель репозитория.** Основной проект — хранение и синхронизация данных Bitrix24. Этот модуль проставляет `SOURCE_ID` сделки на ГК по тексту первого входящего сообщения в открытой линии.

## Зачем

На [colifepacific.bitrix24.com](https://colifepacific.bitrix24.com/crm/deal/kanban/category/0/) лиды часто пишут в WhatsApp (Wazzup) по шаблонным ссылкам. Автоматика матчит текст → источник, пишет комментарий в таймлайн и ставит техполе, чтобы не обрабатывать сделку повторно.

## Скоуп

- Портал: только `colifepacific.bitrix24.com` (`B24_HK_PORTAL_DOMAIN`)
- Линии: `LINE=5` (Sales WABA +85251029674, правила по умолчанию + промокоды) и `LINE=31` (Acquisition 85253435916 — только «Аквизишен WA Target»)
- Воронки: `CATEGORY_ID = 0` (Sales) для правил по умолчанию; `CATEGORY_ID = 9` (Acquisition) для «Аквизишен WA Target»
- Стадии: только «в работе» (`STAGE_SEMANTIC_ID = P`); success (`S`) и fail (`F`) — skip
- Событие: `ONOPENLINEMESSAGEADD`
- У правила можно задать свои `line_id` / `category_id`; иначе берутся `OPEN_LINES_SOURCE_LINE_ID` / `OPEN_LINES_SOURCE_CATEGORY_ID`
- Правила с `promo_value` пишут `UF_CRM_1793861655792` и **не** меняют `SOURCE_ID`

## Пути локального приложения

| Поле в Bitrix | URL |
|---|---|
| Путь для первоначальной установки | `https://db.colifeb24apps.com/api/open-lines/install` |
| Приложение само завершает установку | да |
| Путь обработчика событий | `https://db.colifeb24apps.com/api/webhooks/bitrix/open-lines` |

Одно HK local-приложение (`B24_HK_CLIENT_ID`) — общие OAuth-токены с паузами.  
Права: **CRM + Bizproc + Открытые линии** (`crm`, `bizproc`, `imopenlines`). Без `imopenlines` `event.bind` / `dialog.get` не работают. Отдельное OL-приложение не использовать.

## Flow

```
ONOPENLINEMESSAGEADD (HK)
  → POST /api/webhooks/bitrix/open-lines
  → OpenLineMessageJob
      → client inbound + text match
      → imopenlines.dialog.get → DEAL
      → category 0 + UF empty?
      → crm.deal.update (SOURCE_ID или UF промокода + UF_CRM_1792743514293=Y)
      → crm.timeline.comment.add
```

Стоп-флаг: `UF_CRM_1792743514293` (`[тех поле] Источник проставлен автоматикой?`). Если заполнено — skip.

Если правило с `source_id`: `SOURCE_ID` пустой или источник коннектора OL (`5|ANK_…`, `31|WZ_…`, `WZ…`) — пишем сматченный источник.  
Ручной CRM-источник не перезаписываем: только техполе + комментарий.

Если правило с `promo_value` (таргет WABA Sales): пишем `UF_CRM_1793861655792`, `SOURCE_ID` не трогаем.

Job стартует с задержкой **60 с** (сделка успевает создаться и привязаться к чату).  
Если всё ещё `OL_SOURCE_NO_DEAL` — ещё до 5 ретраев раз в ~60 с. При успехе: `SOURCE_ID` + техполе + комментарий в таймлайне.

## Маппинг v1

| Источник / промо | Поле | Паттерны |
|---|---|---|
| 28hse | `SOURCE_ID=UC_JNWVQW` | `property listing from 28hse`, `我在 28Hse 見到`, `28hse.com/rent`, `28hse.com`, `28hse` |
| FB Groups | `SOURCE_ID=UC_P3614V` | `facebook post`, `saw your facebook` |
| Website organic | `SOURCE_ID=WEB` | `found your website`, `your website and would like` |
| WA - NovDec | `SOURCE_ID=UC_TJK8ZS` | `book a room in advance` |
| Common room | `SOURCE_ID=UC_0FLL9P` | `CommonRoom平台` (префикс; хвост сообщения меняется) |
| Promo Winter (flat) | `UF_CRM_1793861655792=4433` | `I want to rent a flat with a discount. My promo code is WINTER.` |
| Promo Cozy (room) | `UF_CRM_1793861655792=4435` | `…promo code is COZY` |
| Promo Home (room) | `UF_CRM_1793861655792=4437` | `…promo code is HOME` |
| Promo Move (room) | `UF_CRM_1793861655792=4439` | `…promo code is MOVE` |
| Promo Dream (room) | `UF_CRM_1793861655792=4441` | `…promo code is DREAM` |
| Аквизишен WA Target | `SOURCE_ID=32` | LINE **31**, category **9**; `I am the owner and want to rent out my flat`, `rent out my flat on HK Island` |

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
- `B24_HK_CLIENT_ID`, `B24_HK_CLIENT_SECRET` — то же приложение, что для пауз
- `BITRIX_OPEN_LINES_HANDLER_URL` (по умолчанию `{APP_URL}/api/webhooks/bitrix/open-lines`)
- `BITRIX_OPEN_LINES_APPLICATION_TOKEN` (**обязателен**; пустой = reject всех OL webhook / install/save)

## Логи

Канал `bitrix_open_lines` → `storage/logs/bitrix_open_lines.log`.

Ключевые маркеры: `OL_SOURCE_APPLIED`, `OL_SOURCE_SKIP_LINE`, `OL_SOURCE_SKIP_FLAG`, `OL_SOURCE_NO_MATCH`, `OL_SOURCE_NO_DEAL`, `OL_EVENT_BIND_RESULT`.

## Smoke

1. В карточке local-приложения на ГК включить права **CRM** + **Открытые линии**, путь установки — URL выше, переустановить приложение.
2. В логах: `OL_INSTALL_SAVE_OK`, `OL_EVENT_BIND_RESULT` с `result: true` (не `ERROR_EVENT_NOT_FOUND`).
3. Написать в WA тестовое сообщение с текстом `28hse` в сделку category 0.
4. Ожидание: `SOURCE_ID=UC_JNWVQW`, техполе `Y`, комментарий в таймлайне; в логах `OL_SOURCE_APPLIED`.
5. Второе сообщение — `OL_SOURCE_SKIP_FLAG`.

> Источник сообщений для модуля — только Bitrix (`ONOPENLINEMESSAGEADD` → наш webhook). Внешние API мессенджеров в этот контур не входят.
