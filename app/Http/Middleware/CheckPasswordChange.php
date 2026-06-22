<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // Fixes "Undefined method check"

class CheckPasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        // Use Auth:: facade instead of auth() helper
        if (Auth::check() && Auth::user()->must_change_password && !$request->is('change-password*')) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
