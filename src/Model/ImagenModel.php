<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Database;
use PDO;

/**
 * Acceso a datos de las imágenes de posts (tabla post_imagenes).
 *
 * Solo metadatos: el binario físico lo gestiona hal-archivos-api.
 */
class ImagenModel
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** Inserta un metadato de imagen. Devuelve el id nuevo. */
    public function agregar(int $postId, array $c): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO post_imagenes
                (post_id, nombre_archivo, ext, tamano, es_portada, orden)
             VALUES (:pid, :nombre, :ext, :tamano, :es_portada, :orden)'
        );
        $stmt->execute([
            'pid'        => $postId,
            'nombre'     => $c['nombre'],
            'ext'        => $c['ext'],
            'tamano'     => $c['tamano'],
            'es_portada' => $c['es_portada'],
            'orden'      => $c['orden'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Siguiente número de orden disponible para un post. */
    public function siguienteOrden(int $postId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(orden), -1) + 1 FROM post_imagenes WHERE post_id = ?'
        );
        $stmt->execute([$postId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca una imagen por id dentro de un post.
     * Devuelve [id, nombre_archivo, slug] o null.
     */
    public function buscar(int $id, int $postId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.id, i.nombre_archivo, p.slug
               FROM post_imagenes i
               JOIN posts p ON p.id = i.post_id
              WHERE i.id = ? AND i.post_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $postId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Devuelve la imagen de portada actual de un post, o null. */
    public function portadaDe(int $postId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nombre_archivo FROM post_imagenes
              WHERE post_id = ? AND es_portada = 1 LIMIT 1'
        );
        $stmt->execute([$postId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Quita la marca de portada a todas las imágenes de un post. */
    public function limpiarPortada(int $postId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE post_imagenes SET es_portada = 0 WHERE post_id = ?'
        );
        $stmt->execute([$postId]);
    }

    /** Elimina un metadato de imagen. true si borró alguna fila. */
    public function eliminar(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM post_imagenes WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
