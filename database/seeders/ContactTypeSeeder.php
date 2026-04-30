<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContactTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $items = [
            ['code' => 'not_selected', 'name' => 'Не выбран', 'sort' => 100],
            ['code' => 'lead_tenant', 'name' => 'Lead. Tenant', 'sort' => 200],
            ['code' => 'tenant', 'name' => 'Tenant', 'sort' => 300],
            ['code' => 'co_tenant', 'name' => 'Co-tenant', 'sort' => 400],
            ['code' => 'ex_tenant', 'name' => 'Ex-tenant', 'sort' => 500],
            ['code' => 'suppliers', 'name' => 'Suppliers', 'sort' => 600],
            ['code' => 'partners', 'name' => 'Partners', 'sort' => 700],
            ['code' => 'other', 'name' => 'Other', 'sort' => 800],
            ['code' => 'lead_landlord', 'name' => 'Lead. Landlord', 'sort' => 900],
            ['code' => 'landlord', 'name' => 'Landlord', 'sort' => 1000],
            ['code' => 'ex_landlord', 'name' => 'ex-Landlord', 'sort' => 1100],
            ['code' => 'agent', 'name' => 'Agent', 'sort' => 1200],
            ['code' => 'contact', 'name' => 'Contact', 'sort' => 1300],
            ['code' => 'blacklisted_tenant', 'name' => 'Blacklisted tenant', 'sort' => 1400],
        ];

        foreach ($items as $item) {
            DB::table('contact_types')->updateOrInsert(
                ['code' => $item['code']],
                [
                    'name' => $item['name'],
                    'sort' => $item['sort'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
