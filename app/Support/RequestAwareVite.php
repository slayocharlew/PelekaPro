<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Vite;

class RequestAwareVite extends Vite
{
    public function __construct(private readonly Application $app) {}

    public function isRunningHot(): bool
    {
        if (! $this->app->environment('local', 'testing') || ! $this->app->bound('request')) {
            return false;
        }

        // Resolve the current request, not the request that created this singleton.
        $request = $this->app->make('request');
        $host = strtolower(trim($request->getHost(), '[]'));

        if (! in_array($host, ['localhost', '127.0.0.1', '::1'], true) || ! parent::isRunningHot()) {
            return false;
        }

        // An HTTPS page must never import an HTTP development server.
        return ! $request->isSecure()
            || parse_url(trim(file_get_contents($this->hotFile())), PHP_URL_SCHEME) === 'https';
    }
}
