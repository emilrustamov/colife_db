<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DirectoryController extends Controller
{
    /**
     * Display directories page.
     */
    public function index(Request $request): Response
    {
        $filtered = $this->filterDirectoriesForUser($request->user());

        return Inertia::render('Directories/Index', [
            'directories' => array_values($filtered),
        ]);
    }

    /**
     * Return a paginated directory listing with optional sort and search.
     */
    public function list(Request $request, string $directory): JsonResponse
    {
        $config = $this->resolveDirectory($directory);
        $this->authorizeDirectoryAccess($request->user(), $config['key']);

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
        $config = $this->resolveDirectory($directory);
        $this->authorizeDirectoryAccess($request->user(), $config['key']);

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
     * Whether the user may open the directories module (any menu or legacy directories.view).
     */
    public static function userHasAnyDirectoryAccess(?Authenticatable $user): bool
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
            'bitrix-units-snapshot' => [
                'key' => 'bitrix-units-snapshot',
                'title' => 'Bitrix Units Snapshot',
                'icon' => '📡',
                'table' => 'bitrix_units_snapshot',
                'id' => 'id',
                'morph' => 'App\\Models\\BitrixUnitsSnapshot',
            ],
        ];
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
     * @return array<string, array<string, mixed>>
     */
    private function directories(): array
    {
        return self::definitions();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function filterDirectoriesForUser(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $all = $this->directories();
        $out = [];

        foreach ($all as $key => $config) {
            if ($this->userMayViewDirectory($user, $key)) {
                $out[$key] = $config;
            }
        }

        return $out;
    }

    private function userMayViewDirectory(Authenticatable $user, string $directoryKey): bool
    {
        if ($user->can('directories.view')) {
            return true;
        }

        return $user->can('directory.'.$directoryKey);
    }

    private function authorizeDirectoryAccess(?Authenticatable $user, string $directoryKey): void
    {
        abort_if($user === null, 403);
        abort_unless($this->userMayViewDirectory($user, $directoryKey), 403);
    }
}
