<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AIBackendManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AIBackendController extends Controller
{
    /**
     * Display a listing of AI backends.
     */
    public function index(Request $request, AIBackendManager $manager): Response
    {
        $backends = [];
        $defaultBackend = config('ai.default');

        foreach (config('ai.backends', []) as $name => $config) {
            $backend = [
                'name' => $name,
                'driver' => $config['driver'],
                'is_default' => $name === $defaultBackend,
                'model' => $config['model'] ?? null,
                'capabilities' => [],
                'models' => [],
                'status' => 'unknown',
            ];

            try {
                $driver = $manager->driver($name);
                $backend['capabilities'] = $driver->getCapabilities();
                $backend['status'] = 'connected';

                // Try to list models (only for ollama which has local models)
                if ($config['driver'] === 'ollama') {
                    try {
                        $backend['models'] = $driver->listModels();
                    } catch (Throwable $e) {
                        $backend['models'] = [];
                    }
                }
            } catch (Throwable $e) {
                $backend['status'] = 'error';
                $backend['error'] = $e->getMessage();
            }

            $backends[] = $backend;
        }

        return Inertia::render('AIBackends/Index', [
            'backends' => $backends,
            'defaultBackend' => $defaultBackend,
        ]);
    }

    /**
     * Display a specific AI backend.
     */
    public function show(Request $request, AIBackendManager $manager, string $backend): Response
    {
        $config = config("ai.backends.{$backend}");

        if (! $config) {
            abort(404, 'Backend not found');
        }

        $defaultBackend = config('ai.default');
        $backendData = [
            'name' => $backend,
            'driver' => $config['driver'],
            'is_default' => $backend === $defaultBackend,
            'model' => $config['model'] ?? null,
            'capabilities' => [],
            'models' => [],
            'status' => 'unknown',
            'supports_model_management' => false,
        ];

        try {
            $driver = $manager->driver($backend);
            $backendData['capabilities'] = $driver->getCapabilities();
            $backendData['status'] = 'connected';
            $backendData['supports_model_management'] = $driver->supportsModelManagement();

            // List models (works for all backends - managed or not)
            try {
                $backendData['models'] = $driver->listModels();
            } catch (Throwable $e) {
                $backendData['models'] = [];
            }
        } catch (Throwable $e) {
            $backendData['status'] = 'error';
            $backendData['error'] = $e->getMessage();
        }

        return Inertia::render('AIBackends/Show', [
            'backend' => $backendData,
        ]);
    }
}
