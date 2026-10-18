<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EstadoTemporalDocumento;
use App\Enums\FuenteKilometraje;
use App\Support\NormalizadorPatente;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de lógica pura: no tocan la base de datos.
 *
 * Son las que más fácilmente se ejecutan en cualquier parte, y por eso conviene
 * que cubran lo que más caro sería equivocarse: el conteo de días que
 * faltantes y la normalización de la patente.
 *
 * El conteo de días merece una explicación. Un documento que vence "el 20"
 * tiene CERO días restantes el día 20, no menos uno. Esa diferencia de un día
 * es la que separa el mensaje "vence hoy" de "venció ayer", y es el mensaje
 * que después se manda al conductor. Un cálculo mal hecho acá produce avisos
 * con un día de atraso, que es exactamente el problema que el producto vino
 * a resolver.
 *
 * NINGUNA de estas pruebas se ejecutó: no había PHP en el entorno donde se
 * escribió el sistema. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 */
class CalculoDeDiasRestantesTest extends TestCase
{
    public function test_hoy_es_cero_dias_restantes_y_no_menos_uno(): void
    {
        $hoy = Carbon::parse('2026-05-20');
        $vencimiento = Carbon::parse('2026-05-20');

        $dias = (int) $hoy->diffInDays($vencimiento, false);

        $this->assertSame(0, $dias, 'El día del vencimiento hay cero días, no -1.');
    }

    public function test_ayer_vencido_es_menos_uno(): void
    {
        $hoy = Carbon::parse('2026-05-20');
        $vencimiento = Carbon::parse('2026-05-19');

        $dias = (int) $hoy->diffInDays($vencimiento, false);

        $this->assertSame(-1, $dias);
    }

    public function test_dentro_de_veinte_dias(): void
    {
        $hoy = Carbon::parse('2026-05-20');
        $vencimiento = Carbon::parse('2026-06-09');

        $this->assertSame(20, (int) $hoy->diffInDays($vencimiento, false));
    }

    public function test_el_estado_temporal_usa_el_dia_del_vencimiento(): void
    {
        // Fechas relativas a HOY a propósito: `calcular()` usa el día actual,
        // así que una fecha absoluta haría que la prueba dependiera del día
        // en que se ejecute. Sería una prueba que pasa un día y falla otro.
        $vencido = EstadoTemporalDocumento::calcular(Carbon::now()->subDay(), 30);
        $venceHoy = EstadoTemporalDocumento::calcular(Carbon::now(), 30);
        $proximo = EstadoTemporalDocumento::calcular(Carbon::now()->addDays(40), 30);

        $this->assertSame(EstadoTemporalDocumento::Vencido, $vencido);
        $this->assertSame(EstadoTemporalDocumento::PorVencer, $venceHoy);
        $this->assertSame(EstadoTemporalDocumento::Vigente, $proximo);
    }

    public function test_un_documento_anulado_no_aparece_como_vigente_ni_como_vencido(): void
    {
        $estado = EstadoTemporalDocumento::calcular(
            Carbon::now()->addDays(5),
            30,
            'anulado'
        );

        $this->assertSame(EstadoTemporalDocumento::Retirado, $estado);
    }

    public function test_sin_fecha_no_inventa_un_estado_de_vencimiento(): void
    {
        $estado = EstadoTemporalDocumento::calcular(null, 30);

        $this->assertSame(EstadoTemporalDocumento::SinFecha, $estado);
    }

    public function test_ajuste_exige_motivo(): void
    {
        $this->assertTrue(FuenteKilometraje::Ajuste->exigeMotivo());
        $this->assertFalse(FuenteKilometraje::Odometro->exigeMotivo());
        $this->assertFalse(FuenteKilometraje::Mantencion->exigeMotivo());
        $this->assertFalse(FuenteKilometraje::CargaCombustible->exigeMotivo());
    }
}