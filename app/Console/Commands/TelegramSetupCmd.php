<?php

namespace App\Console\Commands;

use App\Services\TelegramBot;
use Illuminate\Console\Command;

class TelegramSetupCmd extends Command
{
    protected $signature = 'telegram:setup {--test : Send a test message if TELEGRAM_CHAT_ID is set}';

    protected $description = 'Resolve Telegram chat_id from bot updates and optionally send a test message';

    /**
     * Show recent chat ids from getUpdates and optionally smoke-test send.
     */
    public function handle(TelegramBot $bot): int
    {
        if ($bot->botToken() === '') {
            $this->error('TELEGRAM_BOT_TOKEN is empty.');

            return self::FAILURE;
        }

        $updates = $bot->getUpdates();
        if ($updates === []) {
            $this->warn('No updates. Open t.me/ColifeErrorsBot and send /start, then run again.');
        } else {
            $seen = [];
            foreach (array_reverse($updates) as $update) {
                $chat = data_get($update, 'message.chat')
                    ?? data_get($update, 'edited_message.chat')
                    ?? data_get($update, 'my_chat_member.chat');
                if (! is_array($chat)) {
                    continue;
                }
                $id = $chat['id'] ?? null;
                if ($id === null || isset($seen[(string) $id])) {
                    continue;
                }
                $seen[(string) $id] = true;
                $title = $chat['title']
                    ?? trim(((string) ($chat['first_name'] ?? '')).' '.((string) ($chat['last_name'] ?? '')))
                    ?: ((string) ($chat['username'] ?? 'chat'));
                $this->line("chat_id={$id} ({$title})");
            }

            if ($seen === []) {
                $this->warn('Updates found, but no chat ids. Send /start to the bot.');
            } else {
                $this->info('Put the desired id into TELEGRAM_CHAT_ID in .env, then php artisan config:clear');
            }
        }

        if ($this->option('test')) {
            if ($bot->chatId() === '') {
                $this->error('TELEGRAM_CHAT_ID is empty; cannot send test.');

                return self::FAILURE;
            }

            $ok = $bot->send('ColifeErrorsBot online');
            if (! $ok) {
                $this->error('Test message failed.');

                return self::FAILURE;
            }
            $this->info('Test message sent.');
        }

        return self::SUCCESS;
    }
}
