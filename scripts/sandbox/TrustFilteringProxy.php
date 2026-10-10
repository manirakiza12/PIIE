<?php
namespace PiieSandbox;

use Illuminate\Http\Request;
use Closure;

final class TrustFilteringProxy
{
    public function handle(Request $request, Closure $next)
    {
        // Reset on every request. No changes to the normal application's middleware/configuration.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
        $forwarded=false;
        foreach ($request->headers->keys() as $name) if ($name==='forwarded' || str_starts_with($name,'x-forwarded-')) $forwarded=true;
        $provided=$request->header('X-PIIE-Sandbox-Proxy');
        if ($forwarded || $provided!==null) {
            $file=app()->environmentPath().'/proxy-token';
            $expected=is_file($file)?trim(file_get_contents($file)):'';
            $parts=parse_url(config('app.url'));
            $authority=($parts['host']??'').(isset($parts['port'])?':'.$parts['port']:'');
            if ($request->server('REMOTE_ADDR')!=='127.0.0.1' || !preg_match('/\A[a-f0-9]{64}\z/',$expected)
                || !is_string($provided) || !hash_equals($expected,$provided)
                || $request->header('host')!==$authority || $request->header('x-forwarded-host')!==$authority
                || $request->header('x-forwarded-proto')!==($parts['scheme']??'')) {
                return (new \Illuminate\Http\Response('Untrusted sandbox proxy.',403))->header('Cache-Control','no-store');
            }
            Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
        }
        $request->headers->remove('X-PIIE-Sandbox-Proxy');
        return $next($request);
    }
}
