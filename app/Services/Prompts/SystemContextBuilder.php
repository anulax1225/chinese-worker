<?php

namespace App\Services\Prompts;

use App\Services\Prompts\Contracts\ContextBuilderInterface;

class SystemContextBuilder implements ContextBuilderInterface
{
    /**
     * Build base system context variables available to all prompts.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'date' => now()->toDateString(),
            'time' => now()->format('H:i'),
            'datetime' => now()->toDateTimeString(),
            'timezone' => config('app.timezone'),
            'app_name' => config('app.name'),
            'app_url' => config('app.url'),
        ];
    }
}
