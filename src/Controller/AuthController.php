<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Controller;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Endpoints de sesión para el módulo de Noticias.
 *
 * La autenticación se delega al servicio central `hal-auth-api`, que emite
 * el JWT con los módulos permitidos. Este módulo solo valida y expone los
 * claims del token recibido.
 */
final class AuthController extends Controller
{
    /** GET /me: devuelve los datos del usuario autenticado (requiere JWT válido). */
    public function me(Request $request, Response $response): Response
    {
        $claims = $request->getAttribute('token'); // claims decodificados del JWT

        // Respaldo: si el middleware JWT no está activo (p. ej. AUTH_ENABLED=false
        // en local), decodifica el token del header Authorization manualmente para
        // que la sesión persista al recargar la página.
        if ($claims === null) {
            $claims = $this->decodificarTokenDeCabecera($request);
        }

        if ($claims === null) {
            return $this->json($response, ['error' => 'No autorizado'], 401);
        }

        return $this->json($response, ['usuario' => $claims]);
    }

    /** Decodifica y valida el JWT del header Authorization; null si no es válido. */
    private function decodificarTokenDeCabecera(Request $request): ?array
    {
        $secret = (string) ($_ENV['JWT_SECRET'] ?? '');
        if ($secret === '') {
            return null;
        }

        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }

        try {
            return (array) \Firebase\JWT\JWT::decode($m[1], new Key($secret, 'HS256'));
        } catch (Throwable) {
            return null;
        }
    }
}
