<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EstadoOperativoVehiculo;
use App\Enums\EstadoTemporalDocumento;
use App\Models\Documento;
use App\Models\Mantencion;
use App\Models\TipoDocumento;
use App\Services\Tenancy\ContextoTenant;
use App\Services\Vehiculos\EstadoOperativoVehiculo as CalculadorDeEstado;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * EL CASO QUE EL DUEÑO DE LA FLOTA SEÑALÓ.
 *
 * "Un vehículo puede estar en mantención y tener documentos vencidos a la
 * vez: el sistema debe mostrar ambos estados sin que uno tape al otro."
 *
 * Esta prueba fija esa regla. Si alguien refactoriza el cálculo del estado y
 * vuelve a un único campo, esta prueba falla, que es exactamente lo que tiene
 * que pasar.
 */
class EstadoVehiculoEnDosEjesTest extends TestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) $this->crearTenant('flota-estado')->id;
        $this->crearConfiguracionAlertas($this->tenantId, [30]);
    }

    /**
     * En mantención Y con documentos vencidos: se ven los dos.
     */
    public function test_muestra_mantencion_y_documentos_vencidos_a_la_vez(): void
    {
        $vehiculo = $this->crearVehiculo($this->tenantId, 'EST-01');

        $this->comoTenant(function () use ($vehiculo): void {
            $this->crearMantencionEnProceso($vehiculo->id);
            $this->crearDocumento($vehiculo->id, $this->dias(5));
        });

        $estado = app(CalculadorDeEstado::class)->paraVehiculo($vehiculo);

        $this->assertSame(
            EstadoOperativoVehiculo::EnMantencion,
            $estado->operativo,
            'El estado operativo debe ser "en mantención".'
        );

        $this->assertSame(
            EstadoTemporalDocumento::Vencido,
            $estado->documental,
            'El estado documental debe ser "vencido", y no quedar tapado por el operativo.'
        );

        $this->assertTrue($estado->estaEnTallerYVencido());
        $this->assertNotNull($estado->mantencionEnCurso);
    }

    /**
     * El detalle de cada documento sobrevive al resumen.
     */
    public function test_el_detalle_de_cada_documento_no_se_pierde_en_el_resumen(): void
    {
        $vehiculo = $this->crearVehiculo($this->tenantId, 'EST-02');

        $this->comoTenant(function () use ($vehiculo): void {
            $this->crearDocumento($vehiculo->id, -10); // vencido
            $this->crearDocumento($vehiculo->id, 10, 'seguro_obligatorio'); // vigente
        });

        $estado = app(CalculadorDeEstado::class)->paraVehiculo($vehiculo);

        $this->assertSame(EstadoTemporalDocumento::Vencido, $estado->documental);
        $this->assertCount(2, $estado->documentos, 'Los dos documentos siguen listados, no solo el peor.');

        $porTipo = $estado->documentos->keyBy(fn (Documento $d) => $d->tipoDocumento?->nombre);

        $this->assertSame(
            EstadoTemporalDocumento::Vencido,
            $porTipo['Permiso de circulación']->estadoTemporal()
        );
        $this->assertSame(
            EstadoTemporalDocumento::PorVencer,
            $porTipo['Seguro obligatorio']->estadoTemporal()
        );
    }

    /**
     * En mantención y TODO en regla: solo aparece el estado operativo, y eso
     * está bien.
     */
    public function test_en_mantencion_sin_vencimientos_solo_muestra_el_estado_operativo(): void
    {
        $vehiculo = $this->crearVehiculo($this->tenantId, 'EST-03');

        $this->comoTenant(function () use ($vehiculo): void {
            $this->crearMantencionEnProceso($vehiculo->id);
            $this->crearDocumento($vehiculo->id, 200); // vigente, lejos
        });

        $estado = app(CalculadorDeEstado::class)->paraVehiculo($vehiculo);

        $this->assertSame(EstadoOperativoVehiculo::EnMantencion, $estado->operativo);
        $this->assertSame(EstadoTemporalDocumento::Vigente, $estado->documental);
        $this->assertFalse($estado->estaEnTallerYVencido());
    }

    /**
     * Con documentos vencidos pero sin mantención: el documento manda en la
     * urgencia, y el operativo sigue diciendo "disponible".
     */
    public function test_vencido_y_disponible_a_la_vez(): void
    {
        $vehiculo = $this->crearVehiculo($this->tenantId, 'EST-04');

        $this->comoTenant(function () use ($vehiculo): void {
            $this->crearDocumento($vehiculo->id, -3);
        });

        $estado = app(CalculadorDeEstado::class)->paraVehiculo($vehiculo);

        $this->assertSame(EstadoOperativoVehiculo::Disponible, $estado->operativo);
        $this->assertSame(EstadoTemporalDocumento::Vencido, $estado->documental);
        $this->assertTrue($estado->hayAlgoCritico);
    }

    private function dias(int $cantidad): string
    {
        return Carbon::now()->addDays($cantidad)->toDateString();
    }

    private function comoTenant(\Closure $operacion): mixed
    {
        return app(ContextoTenant::class)->ejecutarAislado($this->tenantId, $operacion);
    }

    private function crearMantencionEnProceso(string $vehiculoId): void
    {
        Mantencion::create([
            'vehiculo_id' => $vehiculoId,
            'estado' => 'en_proceso',
            'tipo' => 'kilometraje',
            'descripcion' => 'Reparación de frenos',
            'km_programado' => 100_000,
            'fecha_programada' => Carbon::now()->toDateString(),
        ]);
    }

    private function crearDocumento(string $vehiculoId, int $diasRestantes, string $clave = 'permiso_circulacion'): Documento
    {
        $nombre = $clave === 'seguro_obligatorio' ? 'Seguro obligatorio' : 'Permiso de circulación';

        $tipo = TipoDocumento::firstOrCreate(
            ['clave' => $clave],
            ['nombre' => $nombre, 'sujeto' => 'vehiculo', 'activo' => true]
        );

        return Documento::create([
            'vehiculo_id' => $vehiculoId,
            'tipo_documento_id' => $tipo->id,
            'numero' => 'DOC-'.random_int(1000, 9999),
            'fecha_emision' => Carbon::now()->subYear()->toDateString(),
            'fecha_vencimiento' => $this->dias($diasRestantes),
            'estado' => 'vigente',
        ]);
    }
}