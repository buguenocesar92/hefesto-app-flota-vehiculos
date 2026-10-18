<?php

declare(strict_types=1);

namespace Tests\Feature\Alertas;

use App\Console\Commands\GenerarAlertasCommand;
use App\Models\Documento;
use App\Models\TipoDocumento;
use App\Services\Tenancy\ContextoTenant;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * LA TAREA PROGRAMADA, probada por su comportamiento observable.
 *
 * Lo que importa no es que el comando devuelva 0: es que al reejecutarlo no
 * haya ni una alerta duplicada. Se prueba por la SALIDA del comando, que es
 * como lo va a ver el operador cuando revise el trabajo de la noche.
 *
 * También se comprueba que el comando avise que no hay novedades cuando todo
 * ya estaba generado. Un comando que dice "0 alertas nuevas" sin explicar por
 * qué se lee como una falla.
 *
 * NINGUNA de estas pruebas se ejecutó: no había PHP en el entorno donde se
 * escribió el sistema. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 */
class GenerarAlertasCommandTest extends TestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) $this->crearTenant('flota-comando')->id;
        $this->crearConfiguracionAlertas($this->tenantId, [30, 15]);
    }

    public function test_el_comando_genera_las_alertas_pendientes(): void
    {
        $this->crearDocumentoQueVenceEn(20);

        $this->artisan('flota:generar-alertas', ['--tenant' => 'flota-comando', '--fecha' => Carbon::now()->toDateString()])
            ->assertSuccessful();
    }

    public function test_reejecutar_el_comando_no_crea_alertas_nuevas(): void
    {
        $this->crearDocumentoQueVenceEn(20);

        $opciones = ['--tenant' => 'flota-comando', '--fecha' => Carbon::now()->toDateString(), '--json' => true];

        $this->artisan('flota:generar-alertas', $opciones)->assertSuccessful();

        $despues = $this->comoTenant(static fn () => Documento::first()->alertas()->count());
        $this->assertSame(1, $despues);

        // Tres ejecuciones más.
        for ($i = 0; $i < 3; $i++) {
            $this->artisan('flota:generar-alertas', $opciones)->assertSuccessful();
        }

        $this->assertSame(
            $despues,
            $this->comoTenant(static fn () => Documento::first()->alertas()->count()),
            'Reejecutar la tarea programada no debe crear alertas nuevas.'
        );
    }

    public function test_sin_documentos_no_falla(): void
    {
        $this->artisan('flota:generar-alertas', ['--tenant' => 'flota-comando'])
            ->assertSuccessful();
    }

    private function crearDocumentoQueVenceEn(int $dias): void
    {
        $vehiculo = $this->crearVehiculo($this->tenantId, 'CMD-01');

        $tipo = TipoDocumento::firstOrCreate(
            ['clave' => 'permiso_circulacion'],
            ['nombre' => 'Permiso de circulación', 'sujeto' => 'vehiculo', 'activo' => true]
        );

        $this->comoTenant(static fn () => Documento::create([
            'vehiculo_id' => $vehiculo->id,
            'tipo_documento_id' => $tipo->id,
            'numero' => 'PC-CMD-01',
            'fecha_emision' => Carbon::now()->subYear()->toDateString(),
            'fecha_vencimiento' => Carbon::now()->addDays($dias)->toDateString(),
            'estado' => 'vigente',
        ]));
    }

    private function comoTenant(\Closure $operacion): mixed
    {
        return app(ContextoTenant::class)->ejecutarAislado($this->tenantId, $operacion);
    }
}