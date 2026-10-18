<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\NormalizadorPatente;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Normalización de patentes.
 *
 * Por qué importa tanto: la patente es la clave con la que el operador
 * reconoce un vehículo. Si "AB CD 12", "AB·CD-12" y "ABCD12" se guardaran
 * como tres vehículos distintos, la flota tendría el doble o el triple de
 * vehículos, y el aviso de vencimiento del permiso se mandaría una vez por
 * cada variante.
 *
 * NINGUNA de estas pruebas se ejecutó: no había PHP en el entorno donde se
 * escribió el sistema. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 */
class NormalizadorPatenteTest extends TestCase
{
    public function test_normaliza_el_formato_con_espacios(): void
    {
        $this->assertSame('ABCD-12', NormalizadorPatente::normalizar('AB CD 12'));
    }

    public function test_normaliza_el_formato_con_punto_boleto(): void
    {
        $this->assertSame('ABCD-12', NormalizadorPatente::normalizar('AB·CD-12'));
    }

    public function test_normaliza_a_mayusculas(): void
    {
        $this->assertSame('ABCD-12', NormalizadorPatente::normalizar('abcd-12'));
    }

    public function test_todas_las_variantes_dan_la_misma_patente(): void
    {
        $variantes = ['AB CD 12', 'AB·CD-12', 'abcd12', 'AB-CD-12', '  ABCD-12  '];

        $normalizadas = array_map(
            static fn (string $patente): string => NormalizadorPatente::normalizar($patente),
            $variantes
        );

        // Si estas cinco no coinciden, la flota se duplica en cada alta.
        $this->assertCount(1, array_unique($normalizadas));
    }

    public function test_el_formato_con_letras_al_final_se_normaliza(): void
    {
        $this->assertSame('BB-BB-10', NormalizadorPatente::normalizar('BB BB 10'));
    }

    public function test_rechaza_una_patente_que_no_es_chilena(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NormalizadorPatente::normalizar('12345678');
    }

    public function test_intentar_normalizar_devuelve_null_en_vez_de_lanzar(): void
    {
        $this->assertNull(NormalizadorPatente::intentarNormalizar('no es una patente'));
    }

    public function test_el_vin_debe_tener_17_caracteres(): void
    {
        $this->assertTrue(NormalizadorPatente::esVinValido('8AFBR1E19N4K123456'));
        $this->assertFalse(NormalizadorPatente::esVinValido('8AFBR1E19N4K12345'));
    }

    public function test_el_vin_rechaza_las_letras_ambiguas(): void
    {
        // La norma internacional excluye I, O y Q para evitar confusión con
        // el 1 y el 0.
        $vin = 'IOQBR1E19N4K12345';
        $this->assertFalse(NormalizadorPatente::esVinValido($vin));
    }
}