<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;

describe('API Rate Limiting', function () {
    test('api requests are throttled when rate limiting is enabled', function () {
        Config::set('app.api_rate_limit_enabled', true);
        Config::set('app.api_rate_limit', 3);

        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/agents')
                ->assertSuccessful();
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/agents')
            ->assertStatus(429);
    });

    test('api requests are not throttled when rate limiting is disabled', function () {
        Config::set('app.api_rate_limit_enabled', false);
        Config::set('app.api_rate_limit', 3);

        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/agents')
                ->assertSuccessful();
        }
    });

    test('throttle response includes retry-after header', function () {
        Config::set('app.api_rate_limit_enabled', true);
        Config::set('app.api_rate_limit', 1);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/agents')
            ->assertSuccessful();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/agents')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    });

    test('rate limit is applied per user', function () {
        Config::set('app.api_rate_limit_enabled', true);
        Config::set('app.api_rate_limit', 2);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Exhaust user A's limit
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($userA, 'sanctum')
                ->getJson('/api/v1/agents')
                ->assertSuccessful();
        }

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/agents')
            ->assertStatus(429);

        // User B should still have their own limit
        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/v1/agents')
            ->assertSuccessful();
    });
});
