<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CanonicalUrlScheme
{
    public function handle(Request $request, Closure $next)
    {
        $appUrl = (string) config('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $appScheme = parse_url($appUrl, PHP_URL_SCHEME);

        if ($appHost !== null && $request->getHost() === $appHost) {
            if ($appScheme === 'https') {
                $request->server->set('HTTPS', 'on');
            } else {
                $request->server->remove('HTTPS');
            }
        }

        return $next($request);
    }
}
