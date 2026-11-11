<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ol_bot_scope_tools')) {
            Schema::create('ol_bot_scope_tools', function (Blueprint $table) {
                $table->id();
                $table->string('scope_key', 128);
                $table->string('tool_key', 128);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['scope_key', 'tool_key']);
                $table->index('scope_key');
            });
        }

        if (DB::table('ol_bot_scope_tools')->count() > 0) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($this->defaultBindings() as $scope => $tools) {
            foreach (array_values($tools) as $i => $tool) {
                $rows[] = [
                    'scope_key' => $scope,
                    'tool_key' => $tool,
                    'sort_order' => $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('ol_bot_scope_tools')->insert($rows);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ol_bot_scope_tools');
    }

    /**
     * Wikibot-like default tool sets per scope.
     *
     * @return array<string, list<string>>
     */
    private function defaultBindings(): array
    {
        $core = ['qualify_lead', 'leave_chat', 'set_wikibot_in_chat_status'];

        return [
            'main' => [
                'search_apartments',
                'get_min_price',
                'get_apartment_by_unit_number',
                'classify_budget_target',
                'qualify_lead',
                'leave_chat',
                'set_wikibot_in_chat_status',
                'set_move_in_date',
            ],
            'scenario:ejari' => $core,
            'scenario:non_target' => $core,
            'scenario:short_term' => $core,
            'scenario:postponed' => $core,
            'scenario:sharing' => $core,
            'scenario:urgent_move_in' => array_merge($core, ['get_min_price', 'search_apartments']),
            'scenario:out_of_budget' => array_merge($core, ['search_apartments', 'get_min_price']),
            'skill:robot_message' => ['get_apartment_by_unit_number', 'leave_chat', 'set_wikibot_in_chat_status'],
        ];
    }
};
