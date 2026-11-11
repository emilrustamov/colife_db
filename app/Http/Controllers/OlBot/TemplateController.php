<?php

namespace App\Http\Controllers\OlBot;

use App\Http\Controllers\Controller;
use App\Models\OlBotScopeTool;
use App\Models\OlBotTemplateVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    /**
     * @var list<string>
     */
    private const SCENARIOS = [
        'ejari',
        'non_target',
        'out_of_budget',
        'postponed',
        'sharing',
        'short_term',
        'urgent_move_in',
    ];

    /**
     * @var list<string>
     */
    private const SKILLS = [
        'robot_message',
        'operator_summary',
    ];

    /**
     * @var list<string>
     */
    private const TOOLS = [
        'search_apartments',
        'get_min_price',
        'get_apartment_by_unit_number',
        'classify_budget_target',
        'qualify_lead',
        'leave_chat',
        'set_wikibot_in_chat_status',
        'set_move_in_date',
    ];

    /**
     * Redirect templates root to Main page.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('ol-bot.templates.main');
    }

    /**
     * Main instruction + functions bindings.
     */
    public function main(): Response
    {
        [$active, $history, $bindings] = $this->catalog();

        return Inertia::render('OlBot/Templates/Main', [
            'scope' => $this->scopePayload('main', $active->get('main'), $bindings),
            'toolCatalog' => self::TOOLS,
            'history' => $history,
        ]);
    }

    /**
     * Scenarios list.
     */
    public function scenarios(): Response
    {
        [$active, , $bindings] = $this->catalog();

        return Inertia::render('OlBot/Templates/ScenariosIndex', [
            'scenarios' => collect(self::SCENARIOS)->map(
                fn (string $name) => $this->scopePayload(
                    'scenario:'.$name,
                    $active->get('scenario:'.$name),
                    $bindings,
                    $name,
                )
            )->values(),
        ]);
    }

    /**
     * Single scenario editor.
     */
    public function scenario(string $name): Response|RedirectResponse
    {
        if (! in_array($name, self::SCENARIOS, true)) {
            return redirect()->route('ol-bot.templates.scenarios');
        }

        [$active, $history, $bindings] = $this->catalog();
        $scopeKey = 'scenario:'.$name;

        return Inertia::render('OlBot/Templates/Scenario', [
            'scope' => $this->scopePayload($scopeKey, $active->get($scopeKey), $bindings, $name),
            'toolCatalog' => self::TOOLS,
            'history' => $history,
        ]);
    }

    /**
     * Skills list.
     */
    public function skills(): Response
    {
        [$active, , $bindings] = $this->catalog();

        return Inertia::render('OlBot/Templates/SkillsIndex', [
            'skills' => collect(self::SKILLS)->map(
                fn (string $name) => $this->scopePayload(
                    'skill:'.$name,
                    $active->get('skill:'.$name),
                    $bindings,
                    $name,
                )
            )->values(),
        ]);
    }

    /**
     * Single skill editor.
     */
    public function skill(string $name): Response|RedirectResponse
    {
        if (! in_array($name, self::SKILLS, true)) {
            return redirect()->route('ol-bot.templates.skills');
        }

        [$active, $history, $bindings] = $this->catalog();
        $scopeKey = 'skill:'.$name;

        return Inertia::render('OlBot/Templates/Skill', [
            'scope' => $this->scopePayload($scopeKey, $active->get($scopeKey), $bindings, $name),
            'toolCatalog' => self::TOOLS,
            'history' => $history,
        ]);
    }

    /**
     * Tools catalog list.
     */
    public function tools(): Response
    {
        [$active] = $this->catalog();

        return Inertia::render('OlBot/Templates/ToolsIndex', [
            'tools' => collect(self::TOOLS)->map(function (string $name) use ($active) {
                $row = $active->get($name);

                return [
                    'key' => $name,
                    'template_key' => $name,
                    'version' => $row,
                ];
            })->values(),
        ]);
    }

    /**
     * Single tool schema editor.
     */
    public function tool(string $name): Response|RedirectResponse
    {
        if (! in_array($name, self::TOOLS, true)) {
            return redirect()->route('ol-bot.templates.tools');
        }

        [$active, $history] = $this->catalog();

        return Inertia::render('OlBot/Templates/Tool', [
            'tool' => [
                'key' => $name,
                'template_key' => $name,
                'version' => $active->get($name),
            ],
            'history' => $history,
        ]);
    }

    /**
     * Create a new template version and activate it.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template_key' => ['required', 'string', 'max:128'],
            'kind' => ['required', 'string', 'in:instruction,tool'],
            'body' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', 'string', 'max:128'],
        ]);

        DB::transaction(function () use ($data, $request): void {
            OlBotTemplateVersion::query()
                ->where('template_key', $data['template_key'])
                ->where('kind', $data['kind'])
                ->where('is_active', true)
                ->update(['is_active' => false]);

            OlBotTemplateVersion::query()->create([
                'template_key' => $data['template_key'],
                'kind' => $data['kind'],
                'body' => $data['body'],
                'note' => $data['note'] ?? null,
                'is_active' => true,
                'created_by' => $request->user()?->id,
            ]);
        });

        return redirect()
            ->to($this->editUrlForScope($data['scope'] ?? $data['template_key']))
            ->with('status', 'Template saved');
    }

    /**
     * Replace tool bindings for a scope (main / scenario:* / skill:*).
     */
    public function syncTools(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope_key' => ['required', 'string', 'max:128'],
            'tool_keys' => ['array'],
            'tool_keys.*' => ['string', 'max:128'],
        ]);

        if (! $this->isValidScope($data['scope_key'])) {
            return redirect()
                ->route('ol-bot.templates.main')
                ->with('status', 'Invalid scope');
        }

        $allowed = collect(self::TOOLS);
        $tools = collect($data['tool_keys'] ?? [])
            ->filter(fn ($key) => $allowed->contains($key))
            ->unique()
            ->values();

        DB::transaction(function () use ($data, $tools): void {
            OlBotScopeTool::query()->where('scope_key', $data['scope_key'])->delete();
            $now = now();
            $rows = $tools->values()->map(fn (string $tool, int $i) => [
                'scope_key' => $data['scope_key'],
                'tool_key' => $tool,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            if ($rows !== []) {
                OlBotScopeTool::query()->insert($rows);
            }
        });

        return redirect()
            ->to($this->editUrlForScope($data['scope_key']))
            ->with('status', 'Functions updated');
    }

    /**
     * Activate an older version (rollback).
     */
    public function activate(Request $request, OlBotTemplateVersion $version): RedirectResponse
    {
        DB::transaction(function () use ($version, $request): void {
            OlBotTemplateVersion::query()
                ->where('template_key', $version->template_key)
                ->where('kind', $version->kind)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            OlBotTemplateVersion::query()->create([
                'template_key' => $version->template_key,
                'kind' => $version->kind,
                'body' => $version->body,
                'note' => 'rollback to #'.$version->id.($request->input('note') ? ': '.$request->input('note') : ''),
                'is_active' => true,
                'created_by' => $request->user()?->id,
            ]);
        });

        $scope = (string) ($request->input('scope') ?: $this->scopeFromTemplateKey($version->template_key));

        return redirect()
            ->to($this->editUrlForScope($scope))
            ->with('status', 'Version activated');
    }

    /**
     * Show one version body.
     */
    public function show(OlBotTemplateVersion $version): Response
    {
        return Inertia::render('OlBot/TemplateShow', [
            'version' => $version,
            'backUrl' => $this->editUrlForScope($this->scopeFromTemplateKey($version->template_key)),
        ]);
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<string, OlBotTemplateVersion>, 1: \Illuminate\Database\Eloquent\Collection<int, OlBotTemplateVersion>, 2: array<string, \Illuminate\Support\Collection<int, string>>}
     */
    private function catalog(): array
    {
        $active = OlBotTemplateVersion::query()
            ->where('is_active', true)
            ->orderBy('template_key')
            ->get()
            ->keyBy('template_key');

        $history = OlBotTemplateVersion::query()
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'template_key', 'kind', 'note', 'is_active', 'created_by', 'created_at']);

        $bindings = OlBotScopeTool::query()
            ->orderBy('scope_key')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('scope_key')
            ->map(fn ($rows) => $rows->pluck('tool_key')->values())
            ->all();

        return [$active, $history, $bindings];
    }

    /**
     * @param  array<string, \Illuminate\Support\Collection<int, string>>  $bindings
     * @return array<string, mixed>
     */
    private function scopePayload(string $scopeKey, ?OlBotTemplateVersion $version, array $bindings, ?string $label = null): array
    {
        $tools = array_values(($bindings[$scopeKey] ?? null)?->all() ?? []);
        $name = $label ?? ($scopeKey === 'main' ? 'main' : $scopeKey);

        return [
            'scope_key' => $scopeKey,
            'name' => $name,
            'label' => $name,
            'template_key' => $scopeKey === 'main' ? 'main' : $scopeKey,
            'kind' => in_array($scopeKey, self::TOOLS, true) ? 'tool' : 'instruction',
            'version' => $version,
            'tool_keys' => $tools,
            'functions_count' => count($tools),
        ];
    }

    /**
     * @param  string  $scope
     */
    private function isValidScope(string $scope): bool
    {
        if ($scope === 'main') {
            return true;
        }
        if (str_starts_with($scope, 'scenario:')) {
            return in_array(substr($scope, 9), self::SCENARIOS, true);
        }
        if (str_starts_with($scope, 'skill:')) {
            return in_array(substr($scope, 6), self::SKILLS, true);
        }

        return in_array($scope, self::TOOLS, true);
    }

    /**
     * @param  string  $templateKey
     */
    private function scopeFromTemplateKey(string $templateKey): string
    {
        if ($templateKey === 'main' || str_starts_with($templateKey, 'scenario:') || str_starts_with($templateKey, 'skill:')) {
            return $templateKey;
        }
        if (in_array($templateKey, self::TOOLS, true)) {
            return $templateKey;
        }

        return 'main';
    }

    /**
     * @param  string  $scope
     */
    private function editUrlForScope(string $scope): string
    {
        if ($scope === 'main') {
            return route('ol-bot.templates.main');
        }
        if (str_starts_with($scope, 'scenario:')) {
            return route('ol-bot.templates.scenario', ['name' => substr($scope, 9)]);
        }
        if (str_starts_with($scope, 'skill:')) {
            return route('ol-bot.templates.skill', ['name' => substr($scope, 6)]);
        }
        if (in_array($scope, self::TOOLS, true)) {
            return route('ol-bot.templates.tool', ['name' => $scope]);
        }

        return route('ol-bot.templates.main');
    }
}
