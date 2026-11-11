<?php

namespace App\Http\Controllers\OlBot;

use App\Http\Controllers\Controller;
use App\Models\OlBotTemplateVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    /**
     * List templates with active version and history.
     */
    public function index(): Response
    {
        $active = OlBotTemplateVersion::query()
            ->where('is_active', true)
            ->orderBy('template_key')
            ->get();

        $history = OlBotTemplateVersion::query()
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'template_key', 'kind', 'note', 'is_active', 'created_by', 'created_at']);

        return Inertia::render('OlBot/Templates', [
            'active' => $active,
            'history' => $history,
            'keys' => $active->pluck('template_key')->unique()->values(),
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

        return redirect()->route('ol-bot.templates')->with('status', 'Template saved');
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

            $clone = OlBotTemplateVersion::query()->create([
                'template_key' => $version->template_key,
                'kind' => $version->kind,
                'body' => $version->body,
                'note' => 'rollback to #'.$version->id.($request->input('note') ? ': '.$request->input('note') : ''),
                'is_active' => true,
                'created_by' => $request->user()?->id,
            ]);
            unset($clone);
        });

        return redirect()->route('ol-bot.templates')->with('status', 'Version activated');
    }

    /**
     * Show one version body.
     */
    public function show(OlBotTemplateVersion $version): Response
    {
        return Inertia::render('OlBot/TemplateShow', [
            'version' => $version,
        ]);
    }
}
