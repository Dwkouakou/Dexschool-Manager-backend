<?php
use App\Http\Middleware\EnsureIsSchoolAdmin;
use App\Http\Middleware\SuperAdminAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
         $middleware->alias([
            'superadmin' => SuperAdminAuth::class,
            'school.admin' => EnsureIsSchoolAdmin::class,
            // ─── CORRECTIF : ces 3 alias sont fournis par le package
            // spatie/laravel-permission (déjà installé) mais ne
            // s'enregistrent plus automatiquement depuis Laravel 11+ —
            // il faut les déclarer manuellement ici. Sans ça, toute route
            // utilisant ->middleware('permission:xxx') plantait en 500
            // ("Target class [permission] does not exist").
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();