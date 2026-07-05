<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\PostController;
use App\Model\ImagenModel;
use App\Model\PostModel;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Tests del generador de slugs (slugify), en especial la normalización de
 * caracteres Unicode "estilizados" (texto en negrita/cursiva de redes sociales)
 * que antes producían un slug vacío -> "El slug resultante no es válido".
 */
final class PostControllerTest extends TestCase
{
    private function slugify(string $texto): string
    {
        $ctrl = new PostController(
            $this->createMock(PostModel::class),
            $this->createMock(ImagenModel::class),
            static fn (): bool => true
        );
        $metodo = new ReflectionMethod($ctrl, 'slugify');
        $metodo->setAccessible(true);

        return (string) $metodo->invoke($ctrl, $texto);
    }

    public function testSlugifyNormalizaTextoEstilizadoUnicode(): void
    {
        // "Mathematical Sans-Serif Bold" (típico de copiar/pegar de redes).
        $this->assertSame('hospital-antonio', $this->slugify('𝗛𝗢𝗦𝗣𝗜𝗧𝗔𝗟 𝗔𝗡𝗧𝗢𝗡𝗜𝗢'));
    }

    public function testSlugifyNormalizaNegritaMatematica(): void
    {
        // "Mathematical Bold".
        $this->assertSame('salud-publica', $this->slugify('𝐒𝐚𝐥𝐮𝐝 𝐏𝐮𝐛𝐥𝐢𝐜𝐚'));
    }

    public function testSlugifyNormalizaDigitosEstilizados(): void
    {
        $this->assertSame('plan-2026', $this->slugify('Plan 𝟮𝟬𝟮𝟲'));
    }

    public function testSlugifyQuitaTildesYSignos(): void
    {
        $this->assertSame('accion-en-salud', $this->slugify('¡Acción en Salud!'));
    }

    public function testSlugifyTextoNormalSinCambios(): void
    {
        $this->assertSame('nota-de-prensa', $this->slugify('Nota de Prensa'));
    }
}
