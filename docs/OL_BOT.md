# OL Bot (Vibecode / Wikibot stuffing)

Свой open-lines бот на VPS (`vibecode-openlines-bot`) с начинкой Wikibot (main + scenarios + tools). Не путать с Open Lines Source и Wikibot billing.

## Runtime

- Supervisor: `colife-ol-bot` → `node --env-file=…/vibecode-openlines-bot/.env server.js`
- Портал: `colifepacific` · `BOT_ID=43591` · линия `OPENLINE_ID=35`
- Модель: runtime из `ol_bot_settings.ai_model` (UI Main → picker Alaio `/ai/models`); last resort `AI_MODEL`
- Каталог: hourly Tilda AE → `ol_bot_apartments` (+ in-memory cache в боте); optional JSON backup `CATALOG_JSON_PATH`; поиск только локальный; `unitNumber`/`sku` из `editions[].sku`
- CRM writes: Bitrix deal UF на ГК (`CRM_WEBHOOK`, `CRM_ENABLED=1`)
  - `qualify_lead` → `UF_CRM_1763647077`
  - `set_wikibot_in_chat_status` → `UF_CRM_OL_BOT_CHAT_STATUS`
  - `set_move_in_date` → `UF_CRM_OL_BOT_MOVE_IN_DATE` (на сделке)
  - `leave_chat` snapshot → тип/бюджет/срок/проживающие/срочность (+ category)
  - если `dealId` пуст — создаёт сделку автоматически
- e2e (`scripts/e2e-replay.mjs`): кроме ответов проверяет classify + UF на сделке (`crmExpect`)

## Admin (Laravel auth)

- `/ol-bot/templates` → redirect на Main
  - `/ol-bot/templates/main` — Main instruction + AI model picker + checkbox Functions
  - `/ol-bot/templates/scenarios` / `{name}` — список и страница сценария
  - `/ol-bot/templates/skills` / `{name}` — навыки
  - `/ol-bot/templates/tools` / `{name}` — каталог схем функций (отдельно от привязок)
  - привязки tools: `ol_bot_scope_tools` (`POST /ol-bot/templates/scope-tools`)
- `/ol-bot/analytics` — чаты + weekly + каталог (`ol_bot_apartments`, tab=`catalog`) + квота Alaio (`tab=quota&period=day|week|month`); в карточке чата ссылки на OL (`dialog_id`) и сделку (`deal_id`)
- Credits в UI: `tokens/1000 · free|paid · $факт · ≈$оценка` (оценка по тарифу `bitrixgpt-5.5-agent`, 1Ꝟ=$1; thinking сейчас free → `$0`)
- Dialog state в памяти; после рестарта поднимается из `ol_bot_messages` / `ol_bot_chats` (`restoreDialogFromDb`)
- Квота: `OlBotAlaioQuota` → `/v1/ai/usage` + `/v1/ai/quota` + `/v1/ai/models`; tab Quota показывает active model, spend/credits и `%` лимита по каждой модели
- Активные шаблоны: `ol_bot_template_versions` (`is_active=1`); fallback на файлы `kb/`

## Runtime tools scope

- Main → tools из `scope_key=main`
- Active scenario → `scope_key=scenario:{name}`
- Robot template turn → `scope_key=skill:robot_message`
- Всегда дополнительно: `activate_scenario`, `make_answer`, `redirect_to_operator`
- Fallback bindings: `kb/scope_tools.json`

## KB

- `kb/shared_preamble.md`, `kb/main.md`, `kb/scenarios/*`, `kb/skills/*`, `kb/tools/*.json`, `kb/scope_tools.json`
- `config/ol-bot.js` — product knobs (`postponedFromDate`, search/classify); injected as `POSTPONED_FROM_DATE`
- Routing: code (`hydrateStateFromText`) owns sharing/short_term/urgent/postponed; KB/bot point at Dialog state
- `handbook/areas.json` — aliases only (+ catalog DISTINCT at runtime); `handbook/crm.json` — CRM enum labels / budget buckets
- KB prompt: `memory.txt` only; apartments via search tools
