<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // FIX: Merged both withMiddleware blocks into one — having two caused
        // the first block (check_admission) to be silently ignored
        $middleware->alias([
            'check_admission'        => \App\Http\Middleware\CheckAdmissionStatus::class,
            'role'                   => \App\Http\Middleware\RoleMiddleware::class,
            'EnsureAdmissionApproved'=> \App\Http\Middleware\EnsureAdmissionApproved::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/paymongo',
        ]);

        // FIX: Redirect unverified users to the verification notice page
        // This applies to all 'auth' protected routes automatically
        $middleware->redirectUsersTo(function ($request) {
            $user = $request->user();
            if (!$user) return route('login');
            if (!$user->hasVerifiedEmail()) return route('verification.notice');

            return match($user->role) {
                'admin'      => route('admin.index'),
                'admissions' => route('admissions.index'),
                'registrar'  => route('registrar.index'),
                'student'    => route('student.dashboard'),
                'teacher'    => route('teacher.dashboard'),
                'cashier'    => route('cashier.index'),
                default      => route('welcome'),
            };
        });

    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();