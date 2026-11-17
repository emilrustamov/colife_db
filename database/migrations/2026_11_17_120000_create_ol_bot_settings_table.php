<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ol_bot_settings')) {
            Schema::create('ol_bot_settings', function (Blueprint $table) {
                $table->string('key', 64)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        $model = (string) config('ol_bot.ai_model', 'bitrix/bitrixgpt-5.5-thinking');
        $exists = DB::table('ol_bot_settings')->where('key', 'ai_model')->exists();
        if (! $exists) {
            DB::table('ol_bot_settings')->insert([
                'key' => 'ai_model',
                'value' => $model,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ol_bot_settings');
    }
};
