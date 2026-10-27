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
        if (! Schema::hasTable('client_balances')) {
            return;
        }

        Schema::table('client_balances', function (Blueprint $table): void {
            $table->dropUnique('client_balances_unique_period');
        });

        if (Schema::hasColumn('client_balances', 'client_id')) {
            Schema::table('client_balances', function (Blueprint $table): void {
                $table->dropColumn('client_id');
            });
        }

        DB::statement('ALTER TABLE client_balances MODIFY apartment_id CHAR(36) NOT NULL');

        if (Schema::hasColumn('client_balances', 'apartment_id') && ! Schema::hasColumn('client_balances', 'apartment_bitrix_id')) {
            Schema::table('client_balances', function (Blueprint $table): void {
                $table->renameColumn('apartment_id', 'apartment_bitrix_id');
            });
        }

        Schema::table('client_balances', function (Blueprint $table): void {
            $table->unique(['apartment_bitrix_id', 'year', 'month'], 'client_balances_unique_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('client_balances')) {
            return;
        }

        Schema::table('client_balances', function (Blueprint $table): void {
            $table->dropUnique('client_balances_unique_period');
        });

        if (Schema::hasColumn('client_balances', 'apartment_bitrix_id') && ! Schema::hasColumn('client_balances', 'apartment_id')) {
            Schema::table('client_balances', function (Blueprint $table): void {
                $table->renameColumn('apartment_bitrix_id', 'apartment_id');
            });
        }

        if (! Schema::hasColumn('client_balances', 'client_id')) {
            Schema::table('client_balances', function (Blueprint $table): void {
                $table->unsignedBigInteger('client_id')->nullable()->after('id');
            });
        }

        Schema::table('client_balances', function (Blueprint $table): void {
            $table->unique(['apartment_id', 'year', 'month'], 'client_balances_unique_period');
        });
    }
};
