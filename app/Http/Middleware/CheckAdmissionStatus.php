<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; 

class CheckAdmissionStatus { 

public function handle(Request $request, Closure $next)
{
    // Check if user is logged in first
    if (!Auth::check()) {
        return redirect()->route('login');
    }

    $user = Auth::user();

    if (!$user->admission || $user->admission->status !== 'approved') {
        return redirect()->route('student.admissions.index')
            ->with('error', 'Access to the Student Portal is restricted until your admission is approved.');
    }

    return $next($request);
}
}