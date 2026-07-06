<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\ImagenModel;
use Tests\TestCase;

/**
 * Tests del cambio en ImagenModel::buscar que ahora devuelve
 * uuid (del post) y es_portada (de la imagen) en vez de slug.
 */
final class ImagenModelUuidTest extends TestCase
{
    public function testBuscarDevuelveUuidYEsPortada(): void
    {
        $fila = [
            'id'             => 3,
            'nombre_archivo' => 'portada.jpg',
            'es_portada'     => '1',
            'uuid'           => 'bda32ae0-ce84-45d8-9819-7ffb7d0a1be2',
        ];
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetch' => $fila])]);
        $model = new ImagenModel($pdo);

        $result = $model->buscar(3, 5);

        $this->assertNotNull($result);
        $this->assertSame('bda32ae0-ce84-45d8-9819-7ffb7d0a1be2', $result['uuid']);
        $this->assertSame('1', $result['es_portada']);
        $this->assertSame('portada.jpg', $result['nombre_archivo']);
        // No debe contener 'slug' (campo eliminado).
        $this->assertArrayNotHasKey('slug', $result);
    }

    public function testBuscarDevuelveNullSiNoEncuentra(): void
    {
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetch' => false])]);
        $model = new ImagenModel($pdo);

        $this->assertNull($model->buscar(99, 5));
    }

    public function testBuscarDistingueEsPortadaFalse(): void
    {
        $fila = [
            'id'             => 7,
            'nombre_archivo' => 'cuerpo.png',
            'es_portada'     => '0',
            'uuid'           => 'aaa-bbb-ccc',
        ];
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetch' => $fila])]);
        $model = new ImagenModel($pdo);

        $result = $model->buscar(7, 5);

        $this->assertSame('0', $result['es_portada']);
        $this->assertSame('aaa-bbb-ccc', $result['uuid']);
    }
}
