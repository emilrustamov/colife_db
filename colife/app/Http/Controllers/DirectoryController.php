<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class DirectoryController extends Controller
{
    /**
     * Display directories page.
     */
    public function index(): Response
    {
        return Inertia::render('Directories/Index', [
            'directories' => array_values($this->directories()),
        ]);
    }

    /**
     * Return directory table rows.
     */
    public function list(string $directory): JsonResponse
    {
        $config = $this->resolveDirectory($directory);

        $rows = DB::table($config['table'])
            ->orderByDesc($config['id'])
            ->limit(200)
            ->get();

        if (isset($config['hiddenFields']) && is_array($config['hiddenFields'])) {
            $rows = $rows->map(function ($row) use ($config) {
                foreach ($config['hiddenFields'] as $field) {
                    unset($row->{$field});
                }

                return $row;
            });
        }

        return response()->json([
            'directory' => $config,
            'rows' => $rows,
        ]);
    }

    /**
     * Return one row and its timeline.
     */
    public function show(string $directory, string $id): JsonResponse
    {
        $config = $this->resolveDirectory($directory);

        $row = DB::table($config['table'])
            ->where($config['id'], $id)
            ->first();

        abort_if($row === null, 404);

        if (isset($config['hiddenFields']) && is_array($config['hiddenFields'])) {
            foreach ($config['hiddenFields'] as $field) {
                unset($row->{$field});
            }
        }

        $timeline = collect();

        if (Schema::hasTable('activity_logs')) {
            $timeline = DB::table('activity_logs')
                ->where('subject_type', $config['morph'])
                ->where('subject_id', (string) $row->{$config['id']})
                ->orderByDesc('happened_at')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();
        }

        return response()->json([
            'directory' => $config,
            'row' => $row,
            'timeline' => $timeline,
        ]);
    }

    /**
     * Resolve directory configuration.
     */
    private function resolveDirectory(string $key): array
    {
        $directories = $this->directories();
        abort_unless(isset($directories[$key]), 404);

        return $directories[$key];
    }

    /**
     * Return supported directories.
     */
    private function directories(): array
    {
        return [
            'users' => [
                'key' => 'users',
                'title' => 'Users',
                'icon' => '👥',
                'table' => 'users',
                'id' => 'id',
                'morph' => 'App\\Models\\User',
                'hiddenFields' => [
                    'password',
                    'remember_token',
                ],
            ],
            'contact-types' => [
                'key' => 'contact-types',
                'title' => 'Contact Types',
                'icon' => '📇',
                'table' => 'contact_types',
                'id' => 'id',
                'morph' => 'App\\Models\\ContactType',
            ],
            'contacts' => [
                'key' => 'contacts',
                'title' => 'Contacts',
                'icon' => '👤',
                'table' => 'contacts',
                'id' => 'id',
                'morph' => 'App\\Models\\Contact',
            ],
            'contact-phones' => [
                'key' => 'contact-phones',
                'title' => 'Contact Phones',
                'icon' => '📞',
                'table' => 'contact_phones',
                'id' => 'id',
                'morph' => 'App\\Models\\ContactPhone',
            ],
            'contact-emails' => [
                'key' => 'contact-emails',
                'title' => 'Contact Emails',
                'icon' => '✉️',
                'table' => 'contact_emails',
                'id' => 'id',
                'morph' => 'App\\Models\\ContactEmail',
            ],
            'metro-stations' => [
                'key' => 'metro-stations',
                'title' => 'Metro Stations',
                'icon' => '🚇',
                'table' => 'metro_stations',
                'id' => 'id',
                'morph' => 'App\\Models\\MetroStation',
            ],
            'apartment-types' => [
                'key' => 'apartment-types',
                'title' => 'Apartment Types',
                'icon' => '🏢',
                'table' => 'apartment_types',
                'id' => 'id',
                'morph' => 'App\\Models\\ApartmentType',
            ],
            'pipelines' => [
                'key' => 'pipelines',
                'title' => 'Pipelines',
                'icon' => '📈',
                'table' => 'pipelines',
                'id' => 'id',
                'morph' => 'App\\Models\\Pipeline',
            ],
            'stages' => [
                'key' => 'stages',
                'title' => 'Stages',
                'icon' => '🪜',
                'table' => 'stages',
                'id' => 'id',
                'morph' => 'App\\Models\\Stage',
            ],
            'buildings' => [
                'key' => 'buildings',
                'title' => 'Buildings',
                'icon' => '🏗️',
                'table' => 'buildings',
                'id' => 'id',
                'morph' => 'App\\Models\\Building',
            ],
            'apartments' => [
                'key' => 'apartments',
                'title' => 'Apartments',
                'icon' => '🏠',
                'table' => 'apartments',
                'id' => 'id',
                'morph' => 'App\\Models\\Apartment',
            ],
            'units' => [
                'key' => 'units',
                'title' => 'Units',
                'icon' => '🧱',
                'table' => 'units',
                'id' => 'id',
                'morph' => 'App\\Models\\Unit',
            ],
            'unit-stays' => [
                'key' => 'unit-stays',
                'title' => 'Unit Stays',
                'icon' => '🛏️',
                'table' => 'unit_stays',
                'id' => 'id',
                'morph' => 'App\\Models\\UnitStay',
            ],
        ];
    }
}
