<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\HealthController;
use Slim\App;

/**
 * Rutas de autenticación, salud, posts y usuarios. El mapeo URL → controlador vive aquí;
 * la lógica está en App\Controller\* (arquitectura MVC).
 */
return function (App $app): void {
    // Preflight CORS: el navegador envía OPTIONS antes de POST/DELETE cross-origin.
    $app->options('/{routes:.+}', function ($request, $response) {
        return $response;
    });

    $app->get('/health', [HealthController::class, 'index']);
    // /me: permite al frontend verificar sesión (token emitido por hal-auth-api)
    $app->get('/me', [AuthController::class, 'me']);

    // Incluir rutas de usuarios
    (require __DIR__ . '/routes-users.php')($app);

    // Incluir rutas de posts (si existen)
    if (file_exists(__DIR__ . '/routes-posts.php')) {
        (require __DIR__ . '/routes-posts.php')($app);
    }
};
