<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DirectoryController extends Controller
{
    public function root(Request $request): RedirectResponse
    {
        $filtered = $this->forUser($request->user());
        $firstKey = array_key_first($filtered);

        if ($firstKey === null) {
            abort(403);
        }

        return redirect()->route('directories.page', ['directory' => $firstKey]);
    }

    /**
     * Display directories page.
     */
    public function index(Request $request, ?string $directory = null): Response
    {
        $filtered = $this->forUser($request->user());
        $initialDirectoryKey = null;
        $page = 'Directories/Index';

        if ($directory !== null) {
            $config = $this->dir($directory);
            $this->authDir($request->user(), $config['key']);
            $initialDirectoryKey = $config['key'];
            $page = $this->page($config['key']);
        } elseif ($filtered !== []) {
            $initialDirectoryKey = array_key_first($filtered);
        }

        return Inertia::render($page, [
            'directories' => array_values($filtered),
            'initialDirectoryKey' => $initialDirectoryKey,
        ]);
    }

    /**
     * Return a paginated directory listing with optional sort and search.
     */
    public function list(Request $request, string $directory): JsonResponse
    {
        $config = $this->dir($directory);
        $this->authDir($request->user(), $config['key']);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:128'],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 50);
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $sort = isset($validated['sort']) ? (string) $validated['sort'] : '';
        $direction = ($validated['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $table = $config['table'];
        $hidden = isset($config['hiddenFields']) && is_array($config['hiddenFields']) ? $config['hiddenFields'] : [];
        $columns = Schema::getColumnListing($table);
        $visibleColumns = array_values(array_diff($columns, $hidden));

        $query = DB::table($table);

        if ($search !== '') {
            $needle = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function ($q) use ($visibleColumns, $needle): void {
                foreach ($visibleColumns as $col) {
                    $q->orWhere($col, 'like', $needle);
                }
            });
        }

        $sortColumn = $sort !== '' && in_array($sort, $visibleColumns, true) ? $sort : null;

        if ($sortColumn !== null) {
            $query->orderBy($sortColumn, $direction);
        } else {
            $query->orderByDesc($config['id']);
        }

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        $rows = collect($paginator->items())->map(function ($row) use ($hidden) {
            $arr = (array) $row;

            foreach ($hidden as $field) {
                unset($arr[$field]);
            }

            return (object) $arr;
        })->values()->all();

        $this->formatRowsForDisplay($rows, $config['key']);

        return response()->json([
            'directory' => $config,
            'fields' => $visibleColumns,
            'rows' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => max(1, $paginator->lastPage()),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    /**
     * Return one row and its timeline.
     */
    public function show(Request $request, string $directory, string $id): JsonResponse
    {
        $config = $this->dir($directory);
        $this->authDir($request->user(), $config['key']);

        $row = DB::table($config['table'])
            ->where($config['id'], $id)
            ->first();

        abort_if($row === null, 404);

        if (isset($config['hiddenFields']) && is_array($config['hiddenFields'])) {
            foreach ($config['hiddenFields'] as $field) {
                unset($row->{$field});
            }
        }

        $this->formatRowsForDisplay([$row], $config['key']);

        $timeline = collect();
        $subjectId = $this->timelineSubject($row, $config);

        if (Schema::hasTable('activity_logs') && $subjectId !== null) {
            $hidden = isset($config['hiddenFields']) && is_array($config['hiddenFields'])
                ? $config['hiddenFields']
                : [];

            $timeline = DB::table('activity_logs')
                ->where('subject_type', $config['morph'])
                ->where('subject_id', $subjectId)
                ->orderByDesc('happened_at')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(function (object $event) use ($hidden): object {
                    $event->old_values = $this->redactTimelineJson($event->old_values ?? null, $hidden);
                    $event->new_values = $this->redactTimelineJson($event->new_values ?? null, $hidden);

                    return $event;
                });
        }

        return response()->json([
            'directory' => $config,
            'row' => $row,
            'timeline' => $timeline,
        ]);
    }

    /**
     * Whether the user may open the directories module (any menu or legacy directories.view).
     */
    public static function canAccessDirs(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->can('directories.view')) {
            return true;
        }

        foreach (array_keys(self::definitions()) as $key) {
            if ($user->can('directory.'.$key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Directory definitions keyed by route slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
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
            'roles' => [
                'key' => 'roles',
                'title' => 'Roles',
                'icon' => '🛡️',
                'table' => 'roles',
                'id' => 'id',
                'morph' => 'Spatie\\Permission\\Models\\Role',
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
                'id' => 'bitrix_id',
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
                'id' => 'bitrix_id',
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
                'id' => 'bitrix_id',
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
                'id' => 'bitrix_id',
                'morph' => 'App\\Models\\Building',
            ],
            'apartments' => [
                'key' => 'apartments',
                'title' => 'Apartments',
                'icon' => '🏠',
                'table' => 'apartments',
                'id' => 'bitrix_id',
                'morph' => 'App\\Models\\Apartment',
                'hiddenFields' => [
                    'wifi_password',
                ],
            ],
            'units' => [
                'key' => 'units',
                'title' => 'Units',
                'icon' => '🧱',
                'table' => 'units',
                'id' => 'bitrix_id',
                'morph' => 'App\\Models\\Unit',
            ],
            'unit-stays' => [
                'key' => 'unit-stays',
                'title' => 'Tenant Contract',
                'icon' => '🛏️',
                'table' => 'unit_stays',
                'id' => 'id',
                'morph' => 'App\\Models\\UnitStay',
            ],
            'apartment-ownerships' => [
                'key' => 'apartment-ownerships',
                'title' => 'Landlord Contract',
                'icon' => '📜',
                'table' => 'apartment_ownerships',
                'id' => 'id',
                'morph' => 'App\\Models\\ApartmentOwnership',
            ],
            'bitrix-units-snapshot' => [
                'key' => 'bitrix-units-snapshot',
                'title' => 'Фин отчет',
                'icon' => '📡',
                'table' => 'bitrix_units_snapshot',
                'id' => 'id',
                'morph' => 'App\\Models\\BitrixUnitsSnapshot',
            ],
            'disk' => [
                'key' => 'disk',
                'title' => 'Диск',
                'icon' => '📁',
                'table' => 'disk_synced_files',
                'id' => 'id',
                'morph' => 'App\\Models\\DiskSyncedFile',
                'hiddenFields' => [
                    'local_path',
                ],
            ],
            'utilities' => [
                'key' => 'utilities',
                'title' => 'Utilities',
                'icon' => '💡',
                'table' => 'utilities',
                'id' => 'id',
                'morph' => 'App\\Models\\Utility',
            ],
        ];
    }

    /**
     * Resolve directory configuration.
     */
    private function dir(string $key): array
    {
        $directories = $this->directories();
        abort_unless(isset($directories[$key]), 404);

        return $directories[$key];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function directories(): array
    {
        return self::definitions();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function forUser(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $all = $this->directories();
        $out = [];

        foreach ($all as $key => $config) {
            if ($this->mayView($user, $key)) {
                $out[$key] = $config;
            }
        }

        return $out;
    }

    private function mayView(Authenticatable $user, string $directoryKey): bool
    {
        if ($user->can('directories.view')) {
            return true;
        }

        return $user->can('directory.'.$directoryKey);
    }

    private function authDir(?Authenticatable $user, string $directoryKey): void
    {
        abort_if($user === null, 403);
        abort_unless($this->mayView($user, $directoryKey), 403);
    }

    private function page(string $directoryKey): string
    {
        return match ($directoryKey) {
            'users' => 'Directories/Users',
            'roles' => 'Directories/Roles',
            'contact-types' => 'Directories/ContactTypes',
            'contacts' => 'Directories/Contacts',
            'contact-phones' => 'Directories/ContactPhones',
            'contact-emails' => 'Directories/ContactEmails',
            'metro-stations' => 'Directories/MetroStations',
            'apartment-types' => 'Directories/ApartmentTypes',
            'pipelines' => 'Directories/Pipelines',
            'stages' => 'Directories/Stages',
            'buildings' => 'Directories/Buildings',
            'apartments' => 'Directories/Apartments',
            'units' => 'Directories/Units',
            'unit-stays' => 'Directories/UnitStays',
            'apartment-ownerships' => 'Directories/ApartmentOwnerships',
            'bitrix-units-snapshot' => 'Directories/BitrixUnitsSnapshot',
            'disk' => 'Directories/Disk',
            'utilities' => 'Directories/Utilities',
            default => 'Directories/Index',
        };
    }

    /**
     * @param  list<object>  $rows
     */
    private function formatRowsForDisplay(array $rows, string $directoryKey): void
    {
        if ($rows === []) {
            return;
        }

        if ($directoryKey === 'buildings') {
            foreach ($rows as $row) {
                $name = trim((string) ($row->name ?? ''));
                if ($name !== '') {
                    $row->name = 'Buildings '.$name;
                }

                foreach (['pool', 'jacuzzi', 'gym', 'sauna', 'parking', 'elevator', 'security'] as $field) {
                    if (! isset($row->{$field}) || $row->{$field} === null) {
                        continue;
                    }

                    $row->{$field} = (int) $row->{$field} === 1 ? 'Есть' : 'Нет';
                }
            }
        }

        foreach ($this->foreignKeyMappings($directoryKey) as $mapping) {
            $this->formatForeignKeyFieldBatch($rows, $mapping);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function foreignKeyMappings(string $directoryKey): array
    {
        return match ($directoryKey) {
            'contacts' => [
                ['field' => 'contact_type_id', 'table' => 'contact_types', 'id_column' => 'id', 'label_columns' => ['name']],
            ],
            'contact-phones', 'contact-emails' => [
                ['field' => 'contact_id', 'table' => 'contacts', 'id_column' => 'id', 'label_columns' => ['first_name', 'last_name'], 'link_directory' => 'contacts', 'link_id_column' => 'bitrix_id'],
            ],
            'apartments' => [
                ['field' => 'apartment_type_id', 'table' => 'apartment_types', 'id_column' => 'id', 'label_columns' => ['name']],
                ['field' => 'stage_id', 'table' => 'stages', 'id_column' => 'id', 'label_columns' => ['name']],
                ['field' => 'metro_station_id', 'table' => 'metro_stations', 'id_column' => 'id', 'label_columns' => ['name']],
                ['field' => 'building_id', 'table' => 'buildings', 'id_column' => 'id', 'label_columns' => ['name']],
                ['field' => 'landlord_contact_id', 'table' => 'contacts', 'id_column' => 'id', 'label_columns' => ['first_name', 'last_name'], 'link_directory' => 'contacts', 'link_id_column' => 'bitrix_id'],
            ],
            'units' => [
                ['field' => 'apartment_id', 'table' => 'apartments', 'id_column' => 'id', 'label_columns' => ['title'], 'link_directory' => 'apartments', 'link_id_column' => 'bitrix_id'],
                ['field' => 'stage_id', 'table' => 'stages', 'id_column' => 'id', 'label_columns' => ['name']],
            ],
            'unit-stays' => [
                ['field' => 'unit_id', 'table' => 'units', 'id_column' => 'id', 'label_columns' => ['title'], 'link_directory' => 'units', 'link_id_column' => 'bitrix_id'],
                ['field' => 'stage_id', 'table' => 'stages', 'id_column' => 'id', 'label_columns' => ['name']],
                ['field' => 'tenant_contact_id', 'table' => 'contacts', 'id_column' => 'id', 'label_columns' => ['first_name', 'last_name'], 'link_directory' => 'contacts', 'link_id_column' => 'bitrix_id'],
                ['field' => 'co_tenant_contact_id', 'table' => 'contacts', 'id_column' => 'id', 'label_columns' => ['first_name', 'last_name'], 'link_directory' => 'contacts', 'link_id_column' => 'bitrix_id'],
            ],
            'apartment-ownerships' => [
                ['field' => 'apartment_id', 'table' => 'apartments', 'id_column' => 'id', 'label_columns' => ['title'], 'link_directory' => 'apartments', 'link_id_column' => 'bitrix_id'],
                ['field' => 'stage_id', 'table' => 'stages', 'id_column' => 'id', 'label_columns' => ['name']],
            ],
            'bitrix-units-snapshot' => [
                ['field' => 'apart_id', 'table' => 'apartments', 'id_column' => 'bitrix_id', 'label_columns' => ['title'], 'link_directory' => 'apartments', 'link_id_column' => 'bitrix_id'],
            ],
            'utilities' => [
                ['field' => 'apartment_id', 'table' => 'apartments', 'id_column' => 'id', 'label_columns' => ['title'], 'link_directory' => 'apartments', 'link_id_column' => 'bitrix_id'],
                ['field' => 'apartment_bitrix_id', 'table' => 'apartments', 'id_column' => 'bitrix_id', 'label_columns' => ['title'], 'link_directory' => 'apartments', 'link_id_column' => 'bitrix_id'],
            ],
            default => [],
        };
    }

    /**
     * @param  list<object>  $rows
     * @param  array<string, mixed>  $mapping
     */
    private function formatForeignKeyFieldBatch(array $rows, array $mapping): void
    {
        $field = (string) $mapping['field'];
        $table = (string) $mapping['table'];
        $idColumn = (string) $mapping['id_column'];
        /** @var list<string> $labelColumns */
        $labelColumns = $mapping['label_columns'];
        $linkDirectory = isset($mapping['link_directory']) ? (string) $mapping['link_directory'] : null;
        $linkIdColumn = isset($mapping['link_id_column']) ? (string) $mapping['link_id_column'] : null;

        $ids = [];
        foreach ($rows as $row) {
            if (! isset($row->{$field}) || $row->{$field} === null) {
                continue;
            }

            $rawId = trim((string) $row->{$field});
            if ($rawId !== '') {
                $ids[] = $row->{$field};
            }
        }

        $ids = array_values(array_unique($ids, SORT_REGULAR));
        if ($ids === []) {
            return;
        }

        $selectColumns = $labelColumns;
        if (! in_array($idColumn, $selectColumns, true)) {
            $selectColumns[] = $idColumn;
        }
        if ($linkIdColumn !== null && ! in_array($linkIdColumn, $selectColumns, true)) {
            $selectColumns[] = $linkIdColumn;
        }

        $records = DB::table($table)
            ->whereIn($idColumn, $ids)
            ->get($selectColumns)
            ->keyBy($idColumn);

        foreach ($rows as $row) {
            if (! isset($row->{$field}) || $row->{$field} === null) {
                continue;
            }

            $rawId = trim((string) $row->{$field});
            if ($rawId === '') {
                continue;
            }

            $record = $records[$row->{$field}] ?? $records[$rawId] ?? null;
            if ($record === null) {
                continue;
            }

            $parts = [];
            foreach ($labelColumns as $column) {
                $value = trim((string) ($record->{$column} ?? ''));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }

            $label = trim(implode(' ', $parts));
            if ($label === '') {
                continue;
            }

            $row->{$field} = $label;

            if ($linkDirectory !== null && $linkIdColumn !== null) {
                $linkId = trim((string) ($record->{$linkIdColumn} ?? ''));
                if ($linkId !== '') {
                    $row->{$field.'_href'} = '/directories/'.$linkDirectory.'?record='.rawurlencode($linkId);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function timelineSubject(object $row, array $config): ?string
    {
        if (isset($row->id) && $row->id !== null && trim((string) $row->id) !== '') {
            return (string) $row->id;
        }

        $routeKey = (string) ($config['id'] ?? '');
        if ($routeKey !== '' && isset($row->{$routeKey}) && $row->{$routeKey} !== null) {
            $value = trim((string) $row->{$routeKey});

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * @param  list<string>  $hiddenFields
     */
    private function redactTimelineJson(mixed $payload, array $hiddenFields): mixed
    {
        if ($hiddenFields === [] || $payload === null || $payload === '') {
            return $payload;
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (! is_array($decoded)) {
                return $payload;
            }

            foreach ($hiddenFields as $field) {
                if (array_key_exists($field, $decoded)) {
                    $decoded[$field] = '[redacted]';
                }
            }

            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_array($payload)) {
            foreach ($hiddenFields as $field) {
                if (array_key_exists($field, $payload)) {
                    $payload[$field] = '[redacted]';
                }
            }
        }

        return $payload;
    }
}
