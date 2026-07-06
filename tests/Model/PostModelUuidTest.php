<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\PostModel;
use Tests\TestCase;

/**
 * Tests de los cambios UUID en PostModel:
 *  - imagenesDe construye URLs con {uuid}/cover/ y {uuid}/foto/
 *  - mapMeta construye la portada con {uuid}/cover/{nombre}
 *  - publicadoPorUuid devuelve post publicado o null
 */
final class PostModelUuidTest extends TestCase
{
    private string $uuid = 'bda32ae0-ce84-45d8-9819-7ffb7d0a1be2';

    // ── imagenesDe ────────────────────────────────────────────────────

    public function testImagenesDeUsaCoverParaPortada(): void
    {
        $_ENV['ARCHIVOS_BASE_URL'] = 'http://localhost:8002/posts';

        $filas = [
            ['id' => 1, 'nombre_archivo' => 'portada.jpg', 'ext' => 'jpg', 'tamano' => 100, 'es_portada' => 1, 'orden' => 0],
        ];
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetchAll' => $filas])]);
        $model = new PostModel($pdo);

        $imgs = $model->imagenesDe(5, $this->uuid);

        $this->assertCount(1, $imgs);
        $this->assertTrue($imgs[0]['isCover']);
        $this->assertStringContainsString("/{$this->uuid}/cover/portada.jpg", $imgs[0]['url']);

        unset($_ENV['ARCHIVOS_BASE_URL']);
    }

    public function testImagenesDeUsaFotoParaContenido(): void
    {
        $_ENV['ARCHIVOS_BASE_URL'] = 'http://localhost:8002/posts';

        $filas = [
            ['id' => 2, 'nombre_archivo' => 'cuerpo.png', 'ext' => 'png', 'tamano' => 200, 'es_portada' => 0, 'orden' => 1],
        ];
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetchAll' => $filas])]);
        $model = new PostModel($pdo);

        $imgs = $model->imagenesDe(5, $this->uuid);

        $this->assertFalse($imgs[0]['isCover']);
        $this->assertStringContainsString("/{$this->uuid}/foto/cuerpo.png", $imgs[0]['url']);

        unset($_ENV['ARCHIVOS_BASE_URL']);
    }

    public function testImagenesDeDevuelveListaVacia(): void
    {
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetchAll' => []])]);
        $model = new PostModel($pdo);

        $this->assertSame([], $model->imagenesDe(5, $this->uuid));
    }

    // ── publicadoPorUuid ─────────────────────────────────────────────

    public function testPublicadoPorUuidDevuelvePost(): void
    {
        $fila = [
            'id' => 1, 'uuid' => $this->uuid, 'slug' => 'test', 'titulo' => 'Test',
            'excerpt' => '', 'categoria' => 'General', 'fecha_publicacion' => '2026-01-01',
            'autor' => 'Autor', 'cover_color' => 'from-green-100 to-green-200',
            'cuerpo' => '', 'publicado' => 1, 'actualizado_en' => null,
        ];
        // publicadoPorUuid: 1 prepare (SELECT post) + 1 prepare (imagenesDe)
        $pdo = $this->pdo(prepare: [
            $this->stmt(['fetch' => $fila]),
            $this->stmt(['fetchAll' => []]),
        ]);
        $model = new PostModel($pdo);

        $post = $model->publicadoPorUuid($this->uuid);

        $this->assertNotNull($post);
        $this->assertSame($this->uuid, $post['uuid']);
        $this->assertSame('Test', $post['title']);
        $this->assertIsArray($post['imagenes']);
    }

    public function testPublicadoPorUuidDevuelveNullSiNoExiste(): void
    {
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetch' => false])]);
        $model = new PostModel($pdo);

        $this->assertNull($model->publicadoPorUuid('no-existe'));
    }
}
