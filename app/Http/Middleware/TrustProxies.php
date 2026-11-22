<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

class TrustProxies extends Middleware
{
    /**
     * Resolve trusted proxies from cached configuration (`config('app.trusted_proxies')`).
     *
     * @return array<int, string>|string|null
     */
    protected function proxies(): array|string|null
    {
        $configured = config('app.trusted_proxies');

        if (! is_string($configured)) {
            return null;
        }

        $trimmed = trim($configured);

        if ($trimmed === '') {
            return null;
        }

        if ($trimmed === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $trimmed))));
    }
}
