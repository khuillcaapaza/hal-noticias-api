<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Database;
use PDO;

/**
 * Acceso a datos de los posts/noticias (tabla posts) y sus imágenes.
 *
 * Todas las consultas son preparadas (anti inyección SQL — OWASP A03). La forma
 * de salida imita el modelo del sitio (slug/title/excerpt/category/date/author/
 * coverColor) para que el consumo desde hal-site sea trivial.
 */
class PostModel
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    // ── Lectura ───────────────────────────────────────────────────────

    /** Metadatos de todos los posts (incluye no publicados). */
    public function todosMeta(): array
    {
        $rows = $this->pdo->query(
            'SELECT p.uuid, p.slug, p.titulo, p.excerpt, p.categoria, p.fecha_publicacion,
                    p.autor, p.cover_color, p.publicado,
                    (SELECT i.nombre_archivo FROM post_imagenes i
                      WHERE i.post_id = p.id AND i.es_portada = 1 LIMIT 1) AS portada
               FROM posts p ORDER BY p.fecha_publicacion DESC, p.id DESC'
        )->fetchAll();

        return array_map([$this, 'mapMeta'], $rows);
    }

    /**
     * Metadatos de posts publicados con búsqueda y paginación.
     *
     * Filtros admitidos: q (texto en título/excerpt/categoría), category, year, month.
     * Devuelve ['items', 'total', 'page', 'per_page', 'total_pages'].
     */
    public function publicadosBuscar(array $f): array
    {
        $where  = ['p.publicado = 1'];
        $params = [];

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[]      = "(CONCAT(p.titulo, ' ', p.excerpt, ' ', p.categoria) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        $category = trim((string) ($f['category'] ?? ''));
        if ($category !== '') {
            $where[]             = 'p.categoria = :categoria';
            $params[':categoria'] = $category;
        }
        $year = (int) ($f['year'] ?? 0);
        if ($year > 0) {
            $where[]         = 'YEAR(p.fecha_publicacion) = :year';
            $params[':year'] = $year;
        }
        $month = (int) ($f['month'] ?? 0);
        if ($month >= 1 && $month <= 12) {
            $where[]          = 'MONTH(p.fecha_publicacion) = :month';
            $params[':month'] = $month;
        }
        $whereSql = implode(' AND ', $where);

        $stmtTotal = $this->pdo->prepare("SELECT COUNT(*) FROM posts p WHERE {$whereSql}");
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $perPage    = max(1, min(100, (int) ($f['per_page'] ?? 12)));
        $totalPages = (int) max(1, (int) ceil($total / $perPage));
        $page       = max(1, min($totalPages, (int) ($f['page'] ?? 1)));
        $offset     = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT p.uuid, p.slug, p.titulo, p.excerpt, p.categoria, p.fecha_publicacion,
                    p.autor, p.cover_color, p.publicado,
                    (SELECT i.nombre_archivo FROM post_imagenes i
                      WHERE i.post_id = p.id AND i.es_portada = 1 LIMIT 1) AS portada
               FROM posts p WHERE {$whereSql}
              ORDER BY p.fecha_publicacion DESC, p.id DESC
              LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'items'       => array_map([$this, 'mapMeta'], $stmt->fetchAll()),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Metadatos de TODOS los posts (incluye no publicados) con búsqueda y
     * paginación, para el panel de administración.
     *
     * Filtros admitidos: q (texto en título/excerpt/categoría), category, page, per_page.
     * Devuelve ['items', 'total', 'page', 'per_page', 'total_pages'].
     */
    public function todosBuscar(array $f): array
    {
        $where  = ['1 = 1'];
        $params = [];

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[]      = "(CONCAT(p.titulo, ' ', p.excerpt, ' ', p.categoria) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        $category = trim((string) ($f['category'] ?? ''));
        if ($category !== '') {
            $where[]              = 'p.categoria = :categoria';
            $params[':categoria'] = $category;
        }
        $whereSql = implode(' AND ', $where);

        $stmtTotal = $this->pdo->prepare("SELECT COUNT(*) FROM posts p WHERE {$whereSql}");
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $perPage    = max(1, min(100, (int) ($f['per_page'] ?? 9)));
        $totalPages = (int) max(1, (int) ceil($total / $perPage));
        $page       = max(1, min($totalPages, (int) ($f['page'] ?? 1)));
        $offset     = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT p.uuid, p.slug, p.titulo, p.excerpt, p.categoria, p.fecha_publicacion,
                    p.autor, p.cover_color, p.publicado,
                    (SELECT i.nombre_archivo FROM post_imagenes i
                      WHERE i.post_id = p.id AND i.es_portada = 1 LIMIT 1) AS portada
               FROM posts p WHERE {$whereSql}
              ORDER BY p.fecha_publicacion DESC, p.id DESC
              LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'items'       => array_map([$this, 'mapMeta'], $stmt->fetchAll()),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /** Categorías (distintas) de todos los posts, alfabéticamente. */
    public function categoriasTodas(): array
    {
        $rows = $this->pdo->query(
            "SELECT DISTINCT categoria FROM posts
              WHERE categoria <> '' ORDER BY categoria ASC"
        )->fetchAll();

        return array_map(static fn (array $r): string => (string) $r['categoria'], $rows);
    }
    public function aniosPublicados(): array
    {
        $rows = $this->pdo->query(
            'SELECT DISTINCT YEAR(fecha_publicacion) AS y
               FROM posts WHERE publicado = 1
              ORDER BY y DESC'
        )->fetchAll();

        return array_map(static fn (array $r): int => (int) $r['y'], $rows);
    }

    /** Categorías (distintas) de los posts publicados, alfabéticamente. */
    public function categoriasPublicadas(): array
    {
        $rows = $this->pdo->query(
            "SELECT DISTINCT categoria FROM posts
              WHERE publicado = 1 AND categoria <> '' ORDER BY categoria ASC"
        )->fetchAll();

        return array_map(static fn (array $r): string => (string) $r['categoria'], $rows);
    }

    /** Un post publicado completo (con imágenes) por slug, o null. */
    public function publicadoPorSlug(string $slug): ?array
    {
        return $this->porSlugCond($slug, true);
    }

    /** Un post completo (publicado o no, con imágenes) por slug, o null. */
    public function porSlug(string $slug): ?array
    {
        return $this->porSlugCond($slug, false);
    }

    /** Un post completo (publicado o no, con imágenes) por uuid, o null. */
    public function porUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM posts WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $post             = $this->map($row);
        $imagenes         = $this->imagenesDe((int) $row['id'], (string) $row['uuid']);
        $post['imagenes'] = $imagenes;
        $post['cover']    = $this->coverDe($imagenes);

        return $post;
    }

    /** Un post publicado completo (con imágenes) por uuid, o null. */
    public function publicadoPorUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM posts WHERE uuid = ? AND publicado = 1 LIMIT 1'
        );
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $post             = $this->map($row);
        $imagenes         = $this->imagenesDe((int) $row['id'], (string) $row['uuid']);
        $post['imagenes'] = $imagenes;
        $post['cover']    = $this->coverDe($imagenes);

        return $post;
    }

    /** Resuelve el slug (legible) a partir del uuid, o null si no existe. */
    public function slugPorUuid(string $uuid): ?string
    {
        $stmt = $this->pdo->prepare('SELECT slug FROM posts WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        $slug = $stmt->fetchColumn();

        return $slug === false ? null : (string) $slug;
    }

    private function porSlugCond(string $slug, bool $soloPublicado): ?array
    {
        $sql = 'SELECT * FROM posts WHERE slug = ?'
            . ($soloPublicado ? ' AND publicado = 1' : '') . ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $uuid             = (string) $row['uuid'];
        $post             = $this->map($row);
        $imagenes         = $this->imagenesDe((int) $row['id'], $uuid);
        $post['imagenes'] = $imagenes;
        $post['cover']    = $this->coverDe($imagenes);

        return $post;
    }

    public function existeSlug(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM posts WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);

        return $stmt->fetchColumn() !== false;
    }

    public function idPorSlug(string $slug): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM posts WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    // ── Escritura ─────────────────────────────────────────────────────

    /** Inserta un post. Devuelve el id nuevo. */
    public function crear(array $p): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO posts
                (uuid, slug, titulo, excerpt, categoria, fecha_publicacion, autor, cover_color, cuerpo, publicado)
             VALUES
                (:uuid, :slug, :titulo, :excerpt, :categoria, :fecha_publicacion, :autor, :cover_color, :cuerpo, :publicado)'
        );
        $stmt->execute($p);

        return (int) $this->pdo->lastInsertId();
    }

    /** Actualiza un post por slug. true si existe (haya o no cambios). */
    public function actualizar(string $slug, array $p): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE posts SET
                titulo = :titulo, excerpt = :excerpt, categoria = :categoria,
                fecha_publicacion = :fecha_publicacion, autor = :autor,
                cover_color = :cover_color, cuerpo = :cuerpo, publicado = :publicado
              WHERE slug = :slug_actual'
        );
        $stmt->execute([
            'titulo'            => $p['titulo'],
            'excerpt'           => $p['excerpt'],
            'categoria'         => $p['categoria'],
            'fecha_publicacion' => $p['fecha_publicacion'],
            'autor'             => $p['autor'],
            'cover_color'       => $p['cover_color'],
            'cuerpo'            => $p['cuerpo'],
            'publicado'         => $p['publicado'],
            'slug_actual'       => $slug,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        return $this->existeSlug($slug);
    }

    /** Elimina un post (CASCADE borra sus imágenes). true si borró. */
    public function eliminar(string $slug): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM posts WHERE slug = ?');
        $stmt->execute([$slug]);

        return $stmt->rowCount() > 0;
    }

    // ── Imágenes (forma de salida para el front) ──────────────────────

    /** Lista las imágenes de un post con su URL pública basada en uuid. */
    public function imagenesDe(int $postId, string $uuid): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nombre_archivo, ext, tamano, es_portada, orden
               FROM post_imagenes WHERE post_id = ?
              ORDER BY es_portada DESC, orden ASC, id ASC'
        );
        $stmt->execute([$postId]);

        $base = rtrim((string) ($_ENV['ARCHIVOS_BASE_URL'] ?? ''), '/');

        return array_map(static function (array $r) use ($base, $uuid): array {
            $subfolder = (int) $r['es_portada'] === 1 ? 'cover' : 'foto';
            return [
                'id'        => (int) $r['id'],
                'name'      => $r['nombre_archivo'],
                'ext'       => $r['ext'],
                'size'      => (int) $r['tamano'],
                'isCover'   => (int) $r['es_portada'] === 1,
                'url'       => $base . '/' . $uuid . '/' . $subfolder . '/' . rawurlencode((string) $r['nombre_archivo']),
            ];
        }, $stmt->fetchAll());
    }

    /** Devuelve la URL de la imagen de portada de una lista de imágenes, o null. */
    private function coverDe(array $imagenes): ?string
    {
        foreach ($imagenes as $img) {
            if ($img['isCover']) {
                return $img['url'];
            }
        }

        return null;
    }

    // ── Mapeo de filas ────────────────────────────────────────────────

    private function map(array $row): array
    {
        return [
            'uuid'        => $row['uuid'],
            'slug'        => $row['slug'],
            'title'       => $row['titulo'],
            'excerpt'     => $row['excerpt'],
            'category'    => $row['categoria'],
            'date'        => $row['fecha_publicacion'],
            'author'      => $row['autor'],
            'coverColor'  => $row['cover_color'],
            'cuerpo'      => $row['cuerpo'] ?? '',
            'publicado'   => (int) $row['publicado'] === 1,
            'actualizado' => $row['actualizado_en'] ?? null,
        ];
    }

    private function mapMeta(array $row): array
    {
        $base    = rtrim((string) ($_ENV['ARCHIVOS_BASE_URL'] ?? ''), '/');
        $portada = (string) ($row['portada'] ?? '');
        $uuid    = (string) ($row['uuid'] ?? '');
        $cover   = ($portada !== '' && $uuid !== '')
            ? $base . '/' . $uuid . '/cover/' . rawurlencode($portada)
            : null;

        return [
            'uuid'       => $uuid,
            'slug'       => $row['slug'],
            'title'      => $row['titulo'],
            'excerpt'    => $row['excerpt'],
            'category'   => $row['categoria'],
            'date'       => $row['fecha_publicacion'],
            'author'     => $row['autor'],
            'coverColor' => $row['cover_color'],
            'cover'      => $cover,
            'publicado'  => (int) $row['publicado'] === 1,
        ];
    }
}
