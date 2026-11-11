# OL Bot (Vibecode / Wikibot stuffing)

Свой open-lines бот на VPS (`vibecode-openlines-bot`) с начинкой Wikibot (main + scenarios + tools). Не путать с Open Lines Source и Wikibot billing.

## Runtime

- Supervisor: `colife-ol-bot` → `node --env-file=…/vibecode-openlines-bot/.env server.js`
- Портал: `colifepacific` · `BOT_ID=43591` · линия `OPENLINE_ID=35`
- Модель: `AI_MODEL=bitrix/bitrixgpt-5.5-thinking`
- Каталог: hourly Tilda AE → JSON + таблица `ol_bot_apartments`; поиск только локальный
- CRM writes v1: stub в dialog state (`qualify_lead` / `leave_chat` / `set_*`)

## Admin (Laravel auth)

- `/ol-bot/templates` — инструкции/tools, версии, rollback
- `/ol-bot/analytics` — чаты + weekly summary
- Активные шаблоны: `ol_bot_template_versions` (`is_active=1`); fallback на файлы `kb/`

## KB

- `kb/shared_preamble.md`, `kb/main.md`, `kb/scenarios/*`, `kb/skills/*`, `kb/tools/*.json`
- `POSTPONED_FROM_DATE` (default `2026-12-10`) — календарный порог postponed
