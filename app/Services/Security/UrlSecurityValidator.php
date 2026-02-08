<?php

namespace App\Services\Security;

use InvalidArgumentException;

class UrlSecurityValidator
{
    /**
     * Private IPv4 ranges that should be blocked for SSRF protection.
     */
    protected const PRIVATE_IP_RANGES = [
        ['start' => '10.0.0.0', 'end' => '10.255.255.255'],        // 10.0.0.0/8
        ['start' => '172.16.0.0', 'end' => '172.31.255.255'],      // 172.16.0.0/12
        ['start' => '192.168.0.0', 'end' => '192.168.255.255'],    // 192.168.0.0/16
        ['start' => '127.0.0.0', 'end' => '127.255.255.255'],      // 127.0.0.0/8 (localhost)
        ['start' => '169.254.0.0', 'end' => '169.254.255.255'],    // 169.254.0.0/16 (link-local/cloud metadata)
        ['start' => '0.0.0.0', 'end' => '0.255.255.255'],          // 0.0.0.0/8
    ];

    /**
     * @param  array<string>  $blockedHosts
     */
    public function __construct(
        protected bool $blockPrivateIps = false,
        protected array $blockedHosts = [],
    ) {}

    /**
     * Validate URL security (SSRF protection).
     *
     * @throws InvalidArgumentException When URL is blocked
     */
    public function validate(string $url): void
    {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';

        if (empty($host)) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }

        // Check blocked hosts list (always enforced)
        if (! empty($this->blockedHosts)) {
            $blockedHostsLower = array_map('strtolower', $this->blockedHosts);
            if (in_array(strtolower($host), $blockedHostsLower, true)) {
                throw new InvalidArgumentException("Blocked host: {$host}");
            }
        }

        // Skip private IP checks if not enabled
        if (! $this->blockPrivateIps) {
            return;
        }

        // Resolve hostname to IP addresses
        $ips = gethostbynamel($host);

        if ($ips === false) {
            // Could not resolve, allow the request (will fail later if invalid)
            return;
        }

        // Check each resolved IP against private ranges
        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                throw new InvalidArgumentException("SSRF protection: {$host} resolves to private IP {$ip}");
            }
        }
    }

    /**
     * Check if a URL is safe (does not throw, returns boolean).
     */
    public function isSafe(string $url): bool
    {
        try {
            $this->validate($url);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Check if an IP address is in a private/reserved range.
     */
    public function isPrivateIp(string $ip): bool
    {
        $ipLong = ip2long($ip);

        if ($ipLong === false) {
            return false;
        }

        foreach (self::PRIVATE_IP_RANGES as $range) {
            $startLong = ip2long($range['start']);
            $endLong = ip2long($range['end']);

            if ($ipLong >= $startLong && $ipLong <= $endLong) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create instance from webfetch config.
     */
    public static function forWebFetch(): self
    {
        return new self(
            blockPrivateIps: config('webfetch.security.block_private_ips', false),
            blockedHosts: config('webfetch.security.blocked_hosts', []),
        );
    }

    /**
     * Create instance from agent tools config.
     */
    public static function forApiTools(): self
    {
        return new self(
            blockPrivateIps: config('agent.api_tools.block_private_ips', false),
            blockedHosts: config('agent.api_tools.blocked_hosts', []),
        );
    }
}
