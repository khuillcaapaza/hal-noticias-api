<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\PostController;
use App\Model\ImagenModel;
use App\Model\PostModel;
use Tests\TestCase;

/**
 * Tests de los métodos añadidos en la refactorización UUID:
 *  - showByUuid  (ruta pública GET /posts/by-uuid/{uuid})
 *  - relayBorrado ahora recibe (uuid, subfolder, nombre, auth)
 */
final class PostControllerUuidTest extends TestCase
{
    private function ctrl(
        PostModel $posts,
        ImagenModel $imagenes,
        ?callable $relay = null
    ): PostController {
        return new PostController(
            $posts,
            $imagenes,
            $relay ?? static fn (): bool => true
        );
    }

    private function post(string $uuid = 'aaa-111'): array
    {
        return [
            'uuid'       => $uuid,
            'slug'       => 'mi-post',
            'title'      => 'Mi Post',
            'excerpt'    => 'resumen',
            'category'   => 'Salud',
            'date'       => '2026-01-01',
            'author'     => 'Autor',
            'coverColor' => 'from-green-100 to-green-200',
            'cuerpo'     => '',
            'publicado'  => true,
            'imagenes'   => [],
            'cover'      => null,
        ];
    }

    // ── showByUuid ────────────────────────────────────────────────────

    public function testShowByUuidDevuelvePostPublicado(): void
    {
        $posts = $this->createMock(PostModel::class);
        $posts->method('publicadoPorUuid')->with('aaa-111')->willReturn($this->post());

        $ctrl = $this->ctrl($posts, $this->createMock(ImagenModel::class));
        $resp = $ctrl->showByUuid($this->request(), $this->response(), ['uuid' => 'aaa-111']);

        $this->assertSame(200, $resp->getStatusCode());
        $body = $this->jsonBody($resp);
        $this->assertSame('aaa-111', $body['post']['uuid']);
        $this->assertSame('mi-post', $body['post']['slug']);
    }

    public function testShowByUuidDevuelve404SiNoExiste(): void
    {
        $posts = $this->createMock(PostModel::class);
        $posts->method('publicadoPorUuid')->willReturn(null);

        $ctrl = $this->ctrl($posts, $this->createMock(ImagenModel::class));
        $resp = $ctrl->showByUuid($this->request(), $this->response(), ['uuid' => 'no-existe']);

        $this->assertSame(404, $resp->getStatusCode());
    }

    // ── relayBorrado usa uuid + subfolder ─────────────────────────────

    public function testDestroyEnviaUuidYSubfolderAlRelay(): void
    {
        $uuid      = 'bbb-222';
        $portada   = 'cover.jpg';
        $contenido = 'foto.jpg';

        $postData = $this->post($uuid);
        $postData['imagenes'] = [
            ['name' => $portada,   'isCover' => true],
            ['name' => $contenido, 'isCover' => false],
        ];

        $llamadas = [];
        $relay = static function (string $u, string $sf, string $n, string $a) use (&$llamadas): bool {
            $llamadas[] = [$u, $sf, $n];
            return true;
        };

        $posts = $this->createMock(PostModel::class);
        $posts->method('porUuid')->willReturn($postData);
        $posts->method('slugPorUuid')->willReturn('mi-post');
        $posts->method('eliminar')->willReturn(true);

        $ctrl = $this->ctrl($posts, $this->createMock(ImagenModel::class), $relay);
        $resp = $ctrl->destroy($this->request(), $this->response(), ['uuid' => $uuid]);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertCount(2, $llamadas);

        // Portada → subfolder 'cover'
        $this->assertSame([$uuid, 'cover', $portada],   $llamadas[0]);
        // Foto    → subfolder 'foto'
        $this->assertSame([$uuid, 'foto',  $contenido], $llamadas[1]);
    }

    public function testDeleteImagenDeterminarSubfolderPorEsPortada(): void
    {
        $uuid = 'ccc-333';
        $slug = 'mi-post';
        $pid  = 7;

        $relay = static function (string $u, string $sf, string $n, string $a): bool {
            // La imagen es portada → subfolder debe ser 'cover'
            return $sf === 'cover';
        };

        $posts = $this->createMock(PostModel::class);
        $posts->method('slugPorUuid')->willReturn($slug);
        $posts->method('idPorSlug')->willReturn($pid);

        $imagenes = $this->createMock(ImagenModel::class);
        $imagenes->method('buscar')->with(1, $pid)->willReturn([
            'id'              => 1,
            'nombre_archivo'  => 'portada.jpg',
            'uuid'            => $uuid,
            'es_portada'      => '1',
        ]);
        $imagenes->method('eliminar')->willReturn(true);

        $ctrl = $this->ctrl($posts, $imagenes, $relay);
        $resp = $ctrl->deleteImagen(
            $this->request(),
            $this->response(),
            ['uuid' => $uuid, 'id' => '1']
        );

        $this->assertSame(200, $resp->getStatusCode());
        $body = $this->jsonBody($resp);
        $this->assertTrue($body['ok']);
    }
}
