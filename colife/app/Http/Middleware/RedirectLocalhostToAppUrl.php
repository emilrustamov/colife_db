<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLocalhostToAppUrl
{
    /**
     * Redirect localhost requests to the canonical APP_URL.
     *
     * @return \Illuminate\Http\RedirectResponse|Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $localHosts = ['localhost', '127.0.0.1', '::1'];

        if (!in_array($host, $localHosts, true)) {
            return $next($request);
        }

        $appUrl = (string) config('app.url');
        $appUrl = rtrim($appUrl, '/');

        if ($appUrl === '') {
            return $next($request);
        }

        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if ($appHost !== null && strtolower($appHost) === $host) {
            return $next($request);
        }

        return redirect()->to($appUrl.$request->getRequestUri());
    }
}

