<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\ImagenModel;
use App\Model\PostModel;
use App\Support\Controller;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * CRUD de posts/noticias.
 *
 * Lectura pública (GET /posts) y administración protegida por JWT bajo
 * /admin/posts. La subida de imágenes la hace el panel DIRECTAMENTE contra
 * hal-archivos-api (colección "posts"); aquí solo se registra/borra el metadato
 * y, al borrar, se reenvía (servidor-a-servidor) la orden de borrado físico.
 */
final class PostController extends Controller
{
    /** Extensiones de imagen permitidas para los metadatos. */
    private const EXT_PERMITIDAS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private PostModel $posts;
    private ImagenModel $imagenes;

    /** @var callable(string, string, string, string): bool */
    private $relayDelete;

    public function __construct(
        ?PostModel $posts = null,
        ?ImagenModel $imagenes = null,
        ?callable $relayDelete = null
    ) {
        $this->posts       = $posts ?? new PostModel();
        $this->imagenes    = $imagenes ?? new ImagenModel();
        $this->relayDelete = $relayDelete ?? [$this, 'relayBorradoHttp'];
    }

    // ── Lectura pública ───────────────────────────────────────────────

    /** GET /posts — metadatos de posts publicados (búsqueda + paginación). */
    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();

        $resultado = $this->posts->publicadosBuscar([
            'q'        => $q['q']        ?? null,
            'category' => $q['category'] ?? null,
            'year'     => $q['year']     ?? null,
            'month'    => $q['month']    ?? null,
            'page'     => $q['page']     ?? null,
            'per_page' => $q['per_page'] ?? null,
        ]);

        return $this->json($response, [
            'posts' => $resultado['items'],
            'meta'  => [
                'total'       => $resultado['total'],
                'page'        => $resultado['page'],
                'per_page'    => $resultado['per_page'],
                'total_pages' => $resultado['total_pages'],
                'years'       => $this->posts->aniosPublicados(),
                'categories'  => $this->posts->categoriasPublicadas(),
            ],
        ]);
    }

    /** GET /posts/{slug} — un post publicado con sus imágenes. */
    public function show(Request $request, Response $response, array $args): Response
    {
        $post = $this->posts->publicadoPorSlug((string) $args['slug']);
        if ($post === null) {
            return $this->json($response, ['error' => 'Post no encontrado'], 404);
        }

        return $this->json($response, ['post' => $post]);
    }

    /** GET /posts/by-uuid/{uuid} — un post publicado con sus imágenes por UUID. */
    public function showByUuid(Request $request, Response $response, array $args): Response
    {
        $post = $this->posts->publicadoPorUuid((string) $args['uuid']);
        if ($post === null) {
            return $this->json($response, ['error' => 'Post no encontrado'], 404);
        }

        return $this->json($response, ['post' => $post]);
    }

    // ── Administración (requiere JWT) ─────────────────────────────────

    /** GET /admin/posts — todos (incluye no publicados) con búsqueda y paginación. */
    public function adminIndex(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();

        $resultado = $this->posts->todosBuscar([
            'q'        => $q['q']        ?? null,
            'category' => $q['category'] ?? null,
            'page'     => $q['page']     ?? null,
            'per_page' => $q['per_page'] ?? null,
        ]);

        return $this->json($response, [
            'posts' => $resultado['items'],
            'meta'  => [
                'total'       => $resultado['total'],
                'page'        => $resultado['page'],
                'per_page'    => $resultado['per_page'],
                'total_pages' => $resultado['total_pages'],
                'categories'  => $this->posts->categoriasTodas(),
            ],
        ]);
    }

    /** GET /admin/posts/{uuid} — un post completo para edición. */
    public function adminShow(Request $request, Response $response, array $args): Response
    {
        $post = $this->posts->porUuid((string) $args['uuid']);
        if ($post === null) {
            return $this->json($response, ['error' => 'Post no encontrado'], 404);
        }

        return $this->json($response, ['post' => $post]);
    }

    /** POST /admin/posts — crear un post. */
    public function store(Request $request, Response $response): Response
    {
        [$campos, $error] = $this->validar((array) $request->getParsedBody());
        if ($error !== null) {
            return $this->json($response, ['error' => $error], 422);
        }

        if ($this->posts->existeSlug($campos['slug'])) {
            return $this->json($response, ['error' => 'Ya existe un post con ese slug.'], 409);
        }

        $campos['uuid'] = $this->generarUuid();
        $this->posts->crear($campos);

        return $this->json(
            $response,
            ['ok' => true, 'uuid' => $campos['uuid'], 'slug' => $campos['slug']],
            201
        );
    }

    /** PUT /admin/posts/{uuid} — actualizar un post. */
    public function update(Request $request, Response $response, array $args): Response
    {
        $slug = $this->posts->slugPorUuid((string) $args['uuid']);
        if ($slug === null) {
            return $this->json($response, ['error' => 'Post no encontrado'], 404);
        }

        [$campos, $error] = $this->validar((array) $request->getParsedBody(), $slug);
        if ($error !== null) {
            return $this->json($response, ['error' => $error], 422);
        }

        if (!$this->posts->actualizar($slug, $campos)) {
            return $this->json($response, ['error' => 'Post no encontrado'], 404);
        }

        return $this->json($response, ['ok' => true, 'uuid' => (string) $args['uuid'], 'slug' => $slug]);
    }

    /** DELETE /admin/posts/{uuid} — eliminar post + sus imágenes. */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $post = $this->posts->porUuid((string) $args['uuid']);
        if ($post === null) {
            return $this->json($response, ['error' => 'Post no encontrado'], 404);
        }
        $slug = (string) $post['slug'];

        // Borrar primero los binarios físicos (reenviando al servicio de archivos).
        $auth       = $request->getHeaderLine('Authorization');
        $postUuid   = (string) $post['uuid'];
        $noBorrados = [];
        foreach ($post['imagenes'] as $img) {
            $subfolder = $img['isCover'] ? 'cover' : 'foto';
            if (!$this->relayBorrado($postUuid, $subfolder, (string) $img['name'], $auth)) {
                $noBorrados[] = $img['name'];
            }
        }

        // Borrar la fila (CASCADE elimina los metadatos de imagen).
        $this->posts->eliminar($slug);

        $payload = ['ok' => true];
        if ($noBorrados !== []) {
            $payload['advertencia'] = 'Algunas imágenes físicas no pudieron eliminarse.';
            $payload['no_borrados'] = $noBorrados;
        }

        return $this->json($response, $payload);
    }

    // ── Imágenes de un post ───────────────────────────────────────────

    /**
     * POST /admin/posts/{slug}/imagenes — registra el metadato de una imagen ya
     * subida directamente a hal-archivos-api. Si es portada, reemplaza la anterior.
     */
    public function addImagen(Request $request, Response $response, array $args): Response
    {
        $slug = $this->posts->slugPorUuid((string) $args['uuid']);
        if ($slug === null) {
            return $this->json($response, ['error' => 'Post no encontrado'], 404);
        }
        $id = $this->posts->idPorSlug($slug);
        if ($id === null) {
            return $this->json($response, ['error' => 'Post no encontrado'], 404);
        }

        [$campos, $error] = $this->validarImagen((array) $request->getParsedBody());
        if ($error !== null) {
            return $this->json($response, ['error' => $error], 422);
        }

        // Si la nueva imagen es portada, retira y borra la portada anterior.
        if ($campos['es_portada'] === 1) {
            $auth     = $request->getHeaderLine('Authorization');
            $anterior = $this->imagenes->portadaDe($id);
            if ($anterior !== null) {
                $this->relayBorrado((string) $args['uuid'], 'cover', (string) $anterior['nombre_archivo'], $auth);
                $this->imagenes->eliminar((int) $anterior['id']);
            }
            $this->imagenes->limpiarPortada($id);
        }

        $campos['orden'] = $this->imagenes->siguienteOrden($id);
        $nuevoId         = $this->imagenes->agregar($id, $campos);

        return $this->json($response, ['ok' => true, 'id' => $nuevoId], 201);
    }

    /**
     * DELETE /admin/posts/{slug}/imagenes/{id} — borra el metadato y reenvía el
     * borrado físico al servicio de archivos.
     */
    public function deleteImagen(Request $request, Response $response, array $args): Response
    {
        $slug = $this->posts->slugPorUuid((string) $args['uuid']);
        if ($slug === null) {
            return $this->json($response, ['error' => 'Post no encontrado'], 404);
        }
        $pid = $this->posts->idPorSlug($slug);
        if ($pid === null) {
            return $this->json($response, ['error' => 'Post no encontrado'], 404);
        }

        $imagenId  = (int) $args['id'];
        $imagen    = $this->imagenes->buscar($imagenId, $pid);
        if ($imagen === null) {
            return $this->json($response, ['error' => 'Imagen no encontrada'], 404);
        }

        $auth      = $request->getHeaderLine('Authorization');
        $subfolder = (int) $imagen['es_portada'] === 1 ? 'cover' : 'foto';
        $borrado   = $this->relayBorrado(
            (string) $args['uuid'],
            $subfolder,
            (string) $imagen['nombre_archivo'],
            $auth
        );

        $this->imagenes->eliminar($imagenId);

        $payload = ['ok' => true];
        if (!$borrado) {
            $payload['advertencia'] = 'El metadato se eliminó, pero la imagen física no pudo borrarse.';
        }

        return $this->json($response, $payload);
    }

    // ── Validación / normalización ────────────────────────────────────

    /**
     * Valida el cuerpo de creación/edición. Devuelve [campos, error].
     * En edición el slug viene fijado por la ruta y no se modifica.
     */
    private function validar(array $data, ?string $slugFijo = null): array
    {
        $titulo = trim((string) ($data['titulo'] ?? $data['title'] ?? ''));
        if ($titulo === '') {
            return [null, 'El título es obligatorio.'];
        }

        if ($slugFijo !== null) {
            $slug = $slugFijo;
        } else {
            $slug = trim((string) ($data['slug'] ?? ''));
            $slug = $slug !== '' ? $this->slugify($slug) : $this->slugify($titulo);
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return [null, 'El slug resultante no es válido.'];
        }

        $fecha = trim((string) ($data['fecha_publicacion'] ?? $data['date'] ?? ''));
        if (!$this->fechaValida($fecha)) {
            return [null, 'La fecha de publicación debe tener el formato AAAA-MM-DD.'];
        }

        $categoria = trim((string) ($data['categoria'] ?? $data['category'] ?? 'General'));
        if ($categoria === '') {
            $categoria = 'General';
        }

        $autor = trim((string) ($data['autor'] ?? $data['author'] ?? 'Hospital Antonio Lorena'));
        if ($autor === '') {
            $autor = 'Hospital Antonio Lorena';
        }

        $coverColor = trim((string) ($data['cover_color'] ?? $data['coverColor'] ?? 'from-green-100 to-green-200'));
        if ($coverColor === '') {
            $coverColor = 'from-green-100 to-green-200';
        }

        return [[
            'slug'              => mb_substr($slug, 0, 180),
            'titulo'            => mb_substr($titulo, 0, 220),
            'excerpt'           => mb_substr(trim((string) ($data['excerpt'] ?? '')), 0, 500),
            'categoria'         => mb_substr($categoria, 0, 60),
            'fecha_publicacion' => $fecha,
            'autor'             => mb_substr($autor, 0, 160),
            'cover_color'       => mb_substr($coverColor, 0, 80),
            // El cuerpo es HTML generado por el editor (admin con JWT). El consumidor
            // (hal-site) DEBE sanearlo al renderizar (defensa frente a XSS — OWASP A03).
            'cuerpo'            => trim((string) ($data['cuerpo'] ?? $data['body'] ?? '')),
            'publicado'         => filter_var($data['publicado'] ?? true, FILTER_VALIDATE_BOOL) ? 1 : 0,
        ], null];
    }

    /** Valida el registro de una imagen. Devuelve [campos, error]. */
    private function validarImagen(array $data): array
    {
        $nombre = basename(str_replace('\\', '/', (string) ($data['nombre'] ?? $data['name'] ?? '')));
        if ($nombre === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $nombre)) {
            return [null, 'El nombre de archivo no es válido.'];
        }

        $ext = strtolower((string) ($data['ext'] ?? pathinfo($nombre, PATHINFO_EXTENSION)));
        if (!in_array($ext, self::EXT_PERMITIDAS, true)) {
            return [null, 'Extensión no permitida. Solo: ' . implode(', ', self::EXT_PERMITIDAS)];
        }

        $tamano = (int) ($data['tamano'] ?? $data['size'] ?? 0);

        return [[
            'nombre'     => $nombre,
            'ext'        => $ext,
            'tamano'     => max(0, $tamano),
            'es_portada' => filter_var($data['es_portada'] ?? $data['isCover'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
        ], null];
    }

    private function fechaValida(string $fecha): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Genera un UUID v4 (RFC 4122) con bytes aleatorios criptográficos. */
    private function generarUuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // versión 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variante RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Convierte un texto a slug (minúsculas, sin tildes, separado por guiones). */
    private function slugify(string $s): string    {
        $s = $this->normalizarEstilizado(trim($s));
        $s = mb_strtolower($s);
        $s = strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';

        return trim($s, '-');
    }

    /**
     * Convierte caracteres "estilizados" del bloque Unicode Mathematical
     * Alphanumeric Symbols (el texto en negrita/cursiva de redes sociales, p.ej.
     * 𝗛𝗢𝗟𝗔) a su equivalente ASCII A-Z/a-z/0-9. Es autónomo (solo mbstring),
     * sin depender de la extensión intl. Los demás caracteres se dejan igual.
     */
    private function normalizarEstilizado(string $s): string
    {
        // Inicio de cada bloque de letras (26 mayúsculas A-Z + 26 minúsculas a-z).
        static $letras = [
            0x1D400, 0x1D434, 0x1D468, 0x1D49C, 0x1D4D0, 0x1D504,
            0x1D538, 0x1D56C, 0x1D5A0, 0x1D5D4, 0x1D608, 0x1D63C, 0x1D670,
        ];
        // Inicio de cada bloque de dígitos (10 dígitos 0-9).
        static $digitos = [0x1D7CE, 0x1D7D8, 0x1D7E2, 0x1D7EC, 0x1D7F6];

        $salida = '';
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($s, $i, 1, 'UTF-8');
            $cp = mb_ord($ch, 'UTF-8');
            $mapeado = null;

            if ($cp !== false) {
                foreach ($letras as $base) {
                    if ($cp >= $base && $cp < $base + 26) {
                        $mapeado = chr(ord('A') + ($cp - $base));
                        break;
                    }
                    if ($cp >= $base + 26 && $cp < $base + 52) {
                        $mapeado = chr(ord('a') + ($cp - $base - 26));
                        break;
                    }
                }
                if ($mapeado === null) {
                    foreach ($digitos as $base) {
                        if ($cp >= $base && $cp < $base + 10) {
                            $mapeado = chr(ord('0') + ($cp - $base));
                            break;
                        }
                    }
                }
            }

            $salida .= $mapeado ?? $ch;
        }

        return $salida;
    }

    /**
     * Reenvía la orden de borrado físico a hal-archivos-api (servidor-a-servidor),
     * propagando la cabecera Authorization del administrador. Devuelve éxito.
     */
    private function relayBorrado(string $uuid, string $subfolder, string $nombre, string $auth): bool
    {
        return ($this->relayDelete)($uuid, $subfolder, $nombre, $auth);
    }

    /** Implementación HTTP real del relay (curl). Aislada para poder inyectarse en tests. */
    private function relayBorradoHttp(string $uuid, string $subfolder, string $nombre, string $auth): bool
    {
        $base = rtrim((string) ($_ENV['FILES_API_BASE_URL'] ?? ''), '/');
        if ($base === '' || !function_exists('curl_init')) {
            return false;
        }

        // @codeCoverageIgnoreStart
        $headers = ['Content-Type: application/json'];
        if ($auth !== '') {
            $headers[] = 'Authorization: ' . $auth;
        }

        $ch = curl_init($base . '/posts/delete');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_POSTFIELDS     => json_encode(['uuid' => $uuid, 'subfolder' => $subfolder, 'nombre' => $nombre]),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300;
        // @codeCoverageIgnoreEnd
    }
}
