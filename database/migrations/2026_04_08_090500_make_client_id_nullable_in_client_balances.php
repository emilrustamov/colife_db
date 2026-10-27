<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Historical no-op: client_id removed; apartment_bitrix_id is created in create_client_balances.
     */
    public function up(): void
    {
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
