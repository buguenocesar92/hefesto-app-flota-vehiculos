<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FuenteKilometraje;
use App\Services\Kilometraje\KilometrajeIncoherente;
use App\Services\Kilometraje\RegistroDeKilometraje;
use App\Services\Tenancy\ContextoTenant;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * EL KILOMETRAJE NO SE ESCRIBE: SE LEE Y SE DECLARA DE DÓNDE.
 *
 * Requisito de dominio: modelar el origen del dato, no como un campo que
 * alguien inventa.
 *
 * Lo que se comprueba acá:
 *
 *   1. Una lectura normal guarda su fuente y se encadena con la anterior.
 *   2. Un kilometraje MENOR que el último se rechaza... salvo que se declare
 *      ajuste, con motivo y con autor.
 *   3. Un ajuste sin motivo se rechaza.
 *   4. La lectura del taller es una fuente de primer nivel, no un dato más.
 *
 * NINGUNA de estas pruebas se ejecutó: no había PHP en el entorno donde se
 * escribió el sistema. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 */
class RegistroDeKilometrajeTest extends TestCase
{
    private string $tenantId;

    private RegistroDeKilometraje $registro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) $this->crearTenant('flota-km')->id;
        $this->registro = app(RegistroDeKilometraje::class);
    }

    public function test_una_lectura_normal_guarda_su_fuente_y_se_encadena(): void
    {
        $vehiculo = $this->crearVehiculo($this->tenantId, 'KM-001');

        $primera = $this->comoTenant(fn () => $this->registro->registrar(
            vehiculo: $vehiculo,
            km: 100_000,
            fuente: FuenteKilometraje::Odometro,
            fecha: Carbon::now()->subDays(10)->toDateString(),
            usuarioId: $this->usuarioId(),
        ));

        $segunda = $this->comoTenant(fn () => $this->registro->registrar(
            vehiculo: $vehiculo,
            km: 100_450,
            fuente: FuenteKilometraje::Odometro,
            fecha: Carbon::now()->toDateString(),
            usuarioId: $this->usuarioId(),
        ));

        $this->assertSame(FuenteKilometraje::Odometro, $primera->fuente);
        $this->assertNull($primera->lectura_anterior_id, 'La primera lectura no tiene anterior.');
        $this->assertSame($primera->id, $segunda->lectura_anterior_id, 'La segunda se encadena con la primera.');
        $this->assertSame(450, $segunda->tramosRecorridos());
    }

    public function test_un_kilometraje_menor_se_rechaza_sin_ajuste(): void
    {
        $vehiculo = $this->crearVehiculo($this->tenantId, 'KM-002');

        $this->comoTenant(fn () => $this->registro->registrar(
            vehiculo: $vehiculo,
            km: 140_000,
            fuente: FuenteKilometraje::Odometro,
            fecha: Carbon::now()->toDateString(),
            usuarioId: $this->usuarioId(),
        ));

        $this->expectException(KilometrajeIncoherente::class);

        $this->comoTenant(fn () => $this->registro->registrar(
            vehiculo: $vehiculo,
            km: 40_000, // error de tipeo: 40.000 donde iba 140.000
            fuente: FuenteKilometraje::Odometro,
            fecha: Carbon::now()->toDateString(),
            usuarioId: $this->usuarioId(),
        ));
    }

    public function test_un_ajuste_con_motivo_y_autor_si_se_acepta(): void
    {
        $vehiculo = $this->crearVehiculo($this->tenantId, 'KM-003');

        $this->comoTenant(fn () => $this->registro->registrar(
            vehiculo: $vehiculo,
            km: 140_000,
            fuente: FuenteKilometraje::Odometro,
            fecha: Carbon::now()->toDateString(),
            usuarioId: $this->usuarioId(),
        ));

        $ajuste = $this->comoTenant(fn () => $this->registro->registrar(
            vehiculo: $vehiculo,
            km: 100_000,
            fuente: FuenteKilometraje::Ajuste,
            fecha: Carbon::now()->toDateString(),
            usuarioId: $this->usuarioId(),
            motivoAjuste: 'Se cambió el odómetro y el vehículo anterior marcaba 140.000.',
        ));

        $this->assertSame(FuenteKilometraje::Ajuste, $ajuste->fuente);
        $this->assertNotNull($ajuste->motivo_ajuste);
    }

    public function test_un_ajuste_sin_motivo_se_rechaza(): void
    {
        $vehiculo = $this->crearVehiculo($this->tenantId, 'KM-004');

        $this->expectException(KilometrajeIncoherente::class);

        $this->comoTenant(fn () => $this->registro->registrar(
            vehiculo: $vehiculo,
            km: 50_000,
            fuente: FuenteKilometraje::Ajuste,
            fecha: Carbon::now()->toDateString(),
            usuarioId: $this->usuarioId(),
        ));
    }

    public function test_el_kilometraje_del_taller_es_una_fuente_de_primer_nivel(): void
    {
        $vehiculo = $this->crearVehiculo($this->tenantId, 'KM-005');

        $mantencion = $this->comoTenant(fn () => \App\Models\Mantencion::create([
            'vehiculo_id' => $vehiculo->id,
            'estado' => 'completada',
            'tipo' => 'kilometraje',
            'descripcion' => 'Cambio de aceite',
            'km_programado' => 200_000,
            'fecha_programada' => Carbon::now()->subDay()->toDateString(),
            'fecha_ejecucion' => Carbon::now()->toDateString(),
            'km_al_entregar' => 201_250,
        ]));

        $lectura = $this->comoTenant(fn () => $this->registro->registrarDesdeMantencion(
            vehiculo: $vehiculo,
            mantencion: $mantencion,
            km: 201_250,
            usuarioId: $this->usuarioId(),
        ));

        $this->assertSame(FuenteKilometraje::Mantencion, $lectura->fuente);
        $this->assertSame($mantencion->id, $lectura->mantencion_id, 'La lectura apunta al origen.');
        $this->assertSame(201_250, $vehiculo->kilometrajeActual());
    }

    private function usuarioId(): string
    {
        return (string) $this->crearUsuario($this->tenantId, 'operador')->id;
    }

    private function comoTenant(\Closure $operacion): mixed
    {
        return app(ContextoTenant::class)->ejecutarAislado($this->tenantId, $operacion);
    }
}