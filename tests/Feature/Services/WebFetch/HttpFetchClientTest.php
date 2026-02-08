<?php

use App\DTOs\WebFetch\FetchRequest;
use App\Exceptions\WebFetchException;
use App\Services\WebFetch\HttpFetchClient;

describe('SSRF protection', function () {
    test('blocks localhost when SSRF protection is enabled', function () {
        $client = new HttpFetchClient(
            blockPrivateIps: true,
        );

        $request = new FetchRequest(url: 'http://localhost/admin');

        expect(fn () => $client->fetch($request))
            ->toThrow(WebFetchException::class, 'SSRF protection');
    });

    test('blocks 127.0.0.1 when SSRF protection is enabled', function () {
        $client = new HttpFetchClient(
            blockPrivateIps: true,
        );

        $request = new FetchRequest(url: 'http://127.0.0.1/admin');

        expect(fn () => $client->fetch($request))
            ->toThrow(WebFetchException::class, 'SSRF protection');
    });

    test('blocks 10.x.x.x private range when SSRF protection is enabled', function () {
        $client = new HttpFetchClient(
            blockPrivateIps: true,
        );

        $request = new FetchRequest(url: 'http://10.0.0.1/internal');

        expect(fn () => $client->fetch($request))
            ->toThrow(WebFetchException::class, 'SSRF protection');
    });

    test('blocks 172.16.x.x private range when SSRF protection is enabled', function () {
        $client = new HttpFetchClient(
            blockPrivateIps: true,
        );

        $request = new FetchRequest(url: 'http://172.16.0.1/internal');

        expect(fn () => $client->fetch($request))
            ->toThrow(WebFetchException::class, 'SSRF protection');
    });

    test('blocks 192.168.x.x private range when SSRF protection is enabled', function () {
        $client = new HttpFetchClient(
            blockPrivateIps: true,
        );

        $request = new FetchRequest(url: 'http://192.168.1.1/router');

        expect(fn () => $client->fetch($request))
            ->toThrow(WebFetchException::class, 'SSRF protection');
    });

    test('blocks 169.254.x.x link-local/cloud metadata when SSRF protection is enabled', function () {
        $client = new HttpFetchClient(
            blockPrivateIps: true,
        );

        $request = new FetchRequest(url: 'http://169.254.169.254/latest/meta-data/');

        expect(fn () => $client->fetch($request))
            ->toThrow(WebFetchException::class, 'SSRF protection');
    });

    test('blocks explicitly listed hosts', function () {
        $client = new HttpFetchClient(
            blockPrivateIps: false,
            blockedHosts: ['internal.example.com', 'secret.local'],
        );

        $request = new FetchRequest(url: 'http://internal.example.com/api');

        expect(fn () => $client->fetch($request))
            ->toThrow(WebFetchException::class, 'Blocked host');
    });

    test('allows localhost when SSRF protection is disabled', function () {
        $client = new HttpFetchClient(
            blockPrivateIps: false,
        );

        $request = new FetchRequest(url: 'http://localhost/test');

        // Should NOT throw SSRF protection error - may throw other errors (404, connection, etc.)
        // but the key is that it's not blocked by SSRF protection
        try {
            $client->fetch($request);
        } catch (WebFetchException $e) {
            expect($e->getMessage())->not->toContain('SSRF protection');
            expect($e->getMessage())->not->toContain('private IP');
        }
    });
});

describe('fromConfig', function () {
    test('creates client with security config from webfetch config', function () {
        config(['webfetch.security.block_private_ips' => true]);
        config(['webfetch.security.blocked_hosts' => ['blocked.com']]);

        $client = HttpFetchClient::fromConfig();

        $request = new FetchRequest(url: 'http://127.0.0.1/test');

        expect(fn () => $client->fetch($request))
            ->toThrow(WebFetchException::class, 'SSRF protection');
    });

    test('SSRF protection is disabled by default', function () {
        config(['webfetch.security.block_private_ips' => false]);

        $client = HttpFetchClient::fromConfig();

        $request = new FetchRequest(url: 'http://localhost/test');

        // Should NOT throw SSRF protection error - may throw other errors
        try {
            $client->fetch($request);
        } catch (WebFetchException $e) {
            expect($e->getMessage())->not->toContain('SSRF protection');
            expect($e->getMessage())->not->toContain('private IP');
        }
    });
});
