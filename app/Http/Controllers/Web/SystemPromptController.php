<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SystemPrompt;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemPromptController extends Controller
{
    /**
     * Display a listing of system prompts.
     */
    public function index(Request $request): Response
    {
        $query = SystemPrompt::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $prompts = $query->orderBy('name')->cursorPaginate(12)->withQueryString();

        return Inertia::render('SystemPrompts/Index', [
            'prompts' => Inertia::merge(fn () => $prompts->items()),
            'nextCursor' => $prompts->nextCursor()?->encode(),
            'filters' => [
                'search' => $request->input('search'),
                'active' => $request->input('active'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new system prompt.
     */
    public function create(): Response
    {
        return Inertia::render('SystemPrompts/Create');
    }

    /**
     * Display the specified system prompt.
     */
    public function show(SystemPrompt $systemPrompt): Response
    {
        $systemPrompt->load('agents');

        return Inertia::render('SystemPrompts/Show', [
            'prompt' => $systemPrompt,
        ]);
    }

    /**
     * Show the form for editing the specified system prompt.
     */
    public function edit(SystemPrompt $systemPrompt): Response
    {
        return Inertia::render('SystemPrompts/Edit', [
            'prompt' => $systemPrompt,
        ]);
    }
}
