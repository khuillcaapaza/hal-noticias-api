<?php

declare(strict_types=1);

use App\Controller\PostController;
use Slim\App;

/**
 * Rutas de posts/noticias.
 *
 * Lectura pública (GET /posts) y CRUD protegido por JWT bajo /admin/posts.
 * La lógica vive en App\Controller\PostController.
 */
return function (App $app): void {
    // Lectura pública.
    $app->get('/posts', [PostController::class, 'index']);
    $app->get('/posts/{slug}', [PostController::class, 'show']);

    // Administración (requiere JWT).
    $app->get('/admin/posts', [PostController::class, 'adminIndex']);
    $app->get('/admin/posts/{uuid}', [PostController::class, 'adminShow']);
    $app->post('/admin/posts', [PostController::class, 'store']);
    $app->put('/admin/posts/{uuid}', [PostController::class, 'update']);
    $app->delete('/admin/posts/{uuid}', [PostController::class, 'destroy']);

    // Imágenes de un post (portada e incrustadas).
    $app->post('/admin/posts/{uuid}/imagenes', [PostController::class, 'addImagen']);
    $app->delete('/admin/posts/{uuid}/imagenes/{id:[0-9]+}', [PostController::class, 'deleteImagen']);
};
