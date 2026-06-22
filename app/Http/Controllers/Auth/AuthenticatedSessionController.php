<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = Auth::user();

        // FIX: If email is not verified, redirect to verification notice
        // regardless of role — they cannot access any dashboard
        if (!$user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice')
                ->with('info', 'Please verify your email address before continuing.');
        }

        // Email is verified — redirect to role-based dashboard
        return match($user->role) {
            'admin'      => redirect()->route('admin.index'),
            'admissions' => redirect()->route('admissions.index'),
            'registrar'  => redirect()->route('registrar.index'),
            'student'    => redirect()->route('student.dashboard'),
            'teacher'    => redirect()->route('teacher.dashboard'),
            'cashier'    => redirect()->route('cashier.index'),
            default      => redirect()->route('welcome'),
        };
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }
}