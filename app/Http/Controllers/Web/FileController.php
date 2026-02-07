<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /**
     * Display a listing of files.
     */
    public function index(Request $request): Response
    {
        $query = File::where('user_id', $request->user()->id);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('path', 'like', "%{$search}%");
        }

        $files = $query->latest()->cursorPaginate(15)->withQueryString();

        return Inertia::render('Files/Index', [
            'files' => Inertia::merge(fn () => $files->items()),
            'nextCursor' => $files->nextCursor()?->encode(),
            'filters' => [
                'type' => $request->input('type'),
                'search' => $request->input('search'),
            ],
        ]);
    }

    /**
     * Display the specified file (download).
     */
    public function show(Request $request, File $file): StreamedResponse
    {
        $this->authorize('view', $file);

        return Storage::disk('local')->download($file->path, basename($file->path));
    }
}
