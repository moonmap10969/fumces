<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmissionApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user) {
            return redirect()->route('login');
        }

        // Redirect unapproved students to the document upload page
        if ($user->role === 'student' && !$user->is_approved) {
            return redirect()->route('student.documents.index')
                ->with('info', 'Your portal will be fully active once your admission is approved.');
        }

        return $next($request);
    }
}