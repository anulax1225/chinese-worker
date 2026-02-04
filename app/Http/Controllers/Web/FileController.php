<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\File;
use Illuminate\Http\RedirectResponse;
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
     * Store a newly uploaded file.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:102400'], // 100MB max
            'type' => ['required', 'in:input,output,temp'],
        ]);

        $uploadedFile = $request->file('file');
        $path = $uploadedFile->store('files/'.$request->user()->id, 'local');

        File::create([
            'user_id' => $request->user()->id,
            'path' => $path,
            'type' => $validated['type'],
            'size' => $uploadedFile->getSize(),
            'mime_type' => $uploadedFile->getMimeType(),
        ]);

        return redirect()->route('files.index')
            ->with('success', 'File uploaded successfully.');
    }

    /**
     * Display the specified file (download).
     */
    public function show(Request $request, File $file): StreamedResponse
    {
        $this->authorize('view', $file);

        return Storage::disk('local')->download($file->path, basename($file->path));
    }

    /**
     * Remove the specified file.
     */
    public function destroy(Request $request, File $file): RedirectResponse
    {
        $this->authorize('delete', $file);

        // Delete the actual file from storage
        Storage::disk('local')->delete($file->path);

        // Delete the database record
        $file->delete();

        return redirect()->route('files.index')
            ->with('success', 'File deleted successfully.');
    }
}
