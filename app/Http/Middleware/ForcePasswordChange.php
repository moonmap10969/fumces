<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // Fixes "Undefined method check"

class ForcePasswordChange {
    public function handle(Request $request, Closure $next) {
        if (Auth::check() && Auth::user()->needs_password_change) {
            return redirect()->route('password.change')
                ->with('info', 'Please change your student number password to continue.');
        }
        return $next($request);
    }
}