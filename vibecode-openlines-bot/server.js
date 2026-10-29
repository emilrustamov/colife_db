import express from 'express';
import { createBotWorker } from './lib/bot.js';
import { loadKnowledgeBase } from './lib/kb.js';

const port = Number(process.env.PORT || 3000);
const apiKey = process.env.VIBE_API_KEY || '';
const botId = process.env.BOT_ID || '';

if (!apiKey || !botId) {
  console.error('VIBE_API_KEY and BOT_ID are required');
  process.exit(1);
}

const app = express();
const worker = createBotWorker({
  apiKey,
  botId,
  knowledgeBase: loadKnowledgeBase(),
  pollMs: Number(process.env.POLL_MS || 3000),
  catalogRefreshMs: Number(process.env.CATALOG_REFRESH_MS || 60 * 60 * 1000),
  model: process.env.AI_MODEL || 'bitrix/bitrixgpt-5.5',
});
const runtime = worker.start();

app.get('/', (_req, res) => {
  const status = runtime.getStatus();
  res.type('html').send(`<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Colife AI Open Channels bot</title>
  <link rel="icon" href="/_gw/icon">
</head>
<body>
  <h1>Colife AI Open Channels bot</h1>
  <p>Status: running</p>
  <pre>${escapeHtml(JSON.stringify(status, null, 2))}</pre>
</body>
</html>`);
});

app.get('/health', (_req, res) => {
  res.json({ ok: true, ...runtime.getStatus() });
});

app.listen(port, () => {
  console.log(`listening on ${port}, botId=${botId}`);
});

/**
 * @param {string} value
 * @returns {string}
 */
function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');
}
