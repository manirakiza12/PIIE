<?php

namespace App\Http\Middleware;

use App\Support\Permissions\PortalAccessDenial;
use Closure;
use Illuminate\Http\Request;

class StudentMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if ($user && $user->role_id == '7' && $user->account_status != 'disable') {
            if ($user->force_password_change && !$request->routeIs('student.password') && !$request->routeIs('logout')) {
                return redirect()->route('student.password', 'edit')->with('warning', get_phrase('You must change your temporary password before continuing.'));
            }

            // Keep billing, account management and in-progress exam saving/submission accessible.
            $studyRoute = $request->routeIs('student.courses.*', 'student.assignments.*',
                'student.live_classes.show', 'student.live_classes.join', 'student.live_classes.materials',
                'student.online_exam.instructions', 'student.online_exam.start', 'student.online_exam.take', 'student.online_exam.resume');
            if ($studyRoute && ! app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class)->allows($user)) {
                $message = 'Pay at least 20% of your current academic-session school fees, or obtain an authorised study fee waiver, to access learning.';
                if ($request->expectsJson()) { return response()->json(['message' => $message], 403); }
                return redirect()->route('student.fee_manager.list')->with('error', $message);
            }
            return $next($request);
        }

        return PortalAccessDenial::redirect($user, 'student.account_disable');
    }
}
