<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class LibraryTenant
{
    public function handle(Request $request,Closure $next)
    {
        abort_unless((int)$request->user()?->role_id===1,403);
        $request->attributes->set('library_school',(int)$request->route('school'));
        $request->route()->forgetParameter('school');
        return $next($request);
    }
}
