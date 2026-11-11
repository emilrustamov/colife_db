<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ol_bot_apartments')) {
            Schema::create('ol_bot_apartments', function (Blueprint $table) {
                $table->id();
                $table->string('uid', 64);
                $table->string('source', 64)->default('');
                $table->string('title')->default('');
                $table->string('sku', 128)->nullable();
                $table->string('unit_number', 128)->nullable();
                $table->string('link', 512)->nullable();
                $table->string('area', 128)->nullable();
                $table->string('type', 64)->nullable();
                $table->string('capacity', 64)->nullable();
                $table->string('available_from', 128)->nullable();
                $table->string('rent_type', 32)->nullable();
                $table->string('category', 32)->nullable();
                $table->string('price_unit', 32)->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->json('price_variants_json')->nullable();
                $table->text('text_body')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['source', 'uid']);
                $table->index('unit_number');
                $table->index('area');
                $table->index('type');
            });
        }

        if (! Schema::hasTable('ol_bot_template_versions')) {
            Schema::create('ol_bot_template_versions', function (Blueprint $table) {
                $table->id();
                $table->string('template_key', 128);
                $table->string('kind', 32)->default('instruction');
                $table->longText('body');
                $table->string('note')->nullable();
                $table->boolean('is_active')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['template_key', 'kind', 'is_active']);
            });
        }

        if (! Schema::hasTable('ol_bot_chats')) {
            Schema::create('ol_bot_chats', function (Blueprint $table) {
                $table->id();
                $table->string('chat_id', 64);
                $table->string('dialog_id', 128)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->unsignedInteger('messages_count')->default(0);
                $table->unsignedInteger('duration_sec')->nullable();
                $table->unsignedInteger('first_response_sec')->nullable();
                $table->unsignedInteger('avg_response_sec')->nullable();
                $table->unsignedInteger('max_response_sec')->nullable();
                $table->boolean('escalated')->default(false);
                $table->string('escalate_reason')->nullable();
                $table->string('escalate_bucket', 32)->nullable();
                $table->decimal('credits', 12, 3)->default(0);
                $table->string('tags')->nullable();
                $table->string('category', 64)->nullable();
                $table->string('line_id', 32)->nullable();
                $table->timestamps();
                $table->unique('chat_id');
                $table->index('started_at');
                $table->index('escalated');
            });
        }

        if (! Schema::hasTable('ol_bot_messages')) {
            Schema::create('ol_bot_messages', function (Blueprint $table) {
                $table->id();
                $table->string('chat_id', 64);
                $table->string('dialog_id', 128)->nullable();
                $table->string('role', 32);
                $table->text('body')->nullable();
                $table->unsignedInteger('response_sec')->nullable();
                $table->decimal('credits', 12, 3)->default(0);
                $table->json('meta_json')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['chat_id', 'created_at']);
            });
        }

        $this->seedTemplatesFromKb();
    }

    /**
     * Seed active template versions from vibecode kb files.
     */
    private function seedTemplatesFromKb(): void
    {
        if (DB::table('ol_bot_template_versions')->exists()) {
            return;
        }

        $kb = base_path('vibecode-openlines-bot/kb');
        $items = [
            ['shared_preamble', 'instruction', 'shared_preamble.md'],
            ['main', 'instruction', 'main.md'],
            ['scenario:ejari', 'instruction', 'scenarios/ejari.md'],
            ['scenario:non_target', 'instruction', 'scenarios/non_target.md'],
            ['scenario:out_of_budget', 'instruction', 'scenarios/out_of_budget.md'],
            ['scenario:postponed', 'instruction', 'scenarios/postponed.md'],
            ['scenario:sharing', 'instruction', 'scenarios/sharing.md'],
            ['scenario:short_term', 'instruction', 'scenarios/short_term.md'],
            ['scenario:urgent_move_in', 'instruction', 'scenarios/urgent_move_in.md'],
            ['skill:robot_message', 'instruction', 'skills/robot_message.md'],
            ['skill:operator_summary', 'instruction', 'skills/operator_summary.md'],
            ['search_apartments', 'tool', 'tools/search_apartments.json'],
            ['get_min_price', 'tool', 'tools/get_min_price.json'],
            ['get_apartment_by_unit_number', 'tool', 'tools/get_apartment_by_unit_number.json'],
            ['classify_budget_target', 'tool', 'tools/classify_budget_target.json'],
            ['qualify_lead', 'tool', 'tools/qualify_lead.json'],
            ['leave_chat', 'tool', 'tools/leave_chat.json'],
            ['set_wikibot_in_chat_status', 'tool', 'tools/set_wikibot_in_chat_status.json'],
            ['set_move_in_date', 'tool', 'tools/set_move_in_date.json'],
        ];

        $now = now();
        foreach ($items as [$key, $kind, $rel]) {
            $path = $kb.'/'.$rel;
            if (! File::exists($path)) {
                continue;
            }
            DB::table('ol_bot_template_versions')->insert([
                'template_key' => $key,
                'kind' => $kind,
                'body' => File::get($path),
                'note' => 'seed from kb',
                'is_active' => 1,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ol_bot_messages');
        Schema::dropIfExists('ol_bot_chats');
        Schema::dropIfExists('ol_bot_template_versions');
        Schema::dropIfExists('ol_bot_apartments');
    }
};
