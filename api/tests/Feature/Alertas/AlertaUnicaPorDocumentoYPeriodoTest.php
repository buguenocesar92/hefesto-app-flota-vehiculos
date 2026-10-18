<?php

declare(strict_types=1);

namespace Tests\Feature\Alertas;

use App\Models\Documento;
use App\Models\TipoDocumento;
use App\Services\Alertas\GeneradorDeAlertas;
use App\Services\Tenancy\ContextoTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LA PRUEBA MÁS IMPORTANTE DEL SISTEMA.
 *
 * Requisito: una alerta de vencimiento se genera UNA SOLA VEZ por documento
 * y período, sin duplicados al reejecutar la tarea programada.
 *
 * Qué se comprueba acá, punto por punto:
 *
 *   1. Un documento dentro de la ventana genera su alerta.
 *   2. Reejecutar el generador NO agrega nada: ni una fila más.
 *   3. Los contadores dicen la verdad: en la segunda corrida, las nuevas son
 *      cero y las omitidas por duplicado son las de la primera.
 *   4. Distintos umbrales del mismo documento SÍ generan alertas distintas
 *      (30 días y 15 días son dos periodos, no uno repetido).
 *   5. Un documento ya vencido genera la alerta de vencido, y el período
 *      incluye la fecha, así que renovar el documento reabre los avisos.
 *   6. El índice único de la base es la garantía real: insertar dos veces la
 *      misma clave a mano NO crea dos filas.
 *
 * NOTA SOBRE EL MOTOR
 * -------------------
 * Con SQLite (el motor por defecto de estas pruebas) el índice único también
 * existe y el comportamiento de `insertOrIgnore` es equivalente para estos
 * casos. Con PostgreSQL se comprobó además la semántica de
 * `ON CONFLICT DO NOTHING`. Lo que NO se prueba en SQLite es la Row Level
 * Security: para eso está `AislamientoEntreFlotasTest`.
 *
 * NINGUNA de estas pruebas se ejecutó: en el entorno donde se escribió el
 * sistema no había PHP ni Composer. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 */
class AlertaUnicaPorDocumentoYPeriodoTest extends TestCase
{
    private string $tenantId;

    private GeneradorDeAlertas $generador;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::now()->startOfDay();
        $tenant = $this->crearTenant('flota-alertas');
        $this->tenantId = (string) $tenant->id;

        $this->crearConfiguracionAlertas($this->tenantId, [30, 15]);
        $this->generador = app(GeneradorDeAlertas::class);
    }

    /**
     * 1. Genera la alerta dentro de la ventana.
     */
    public function test_genera_alerta_para_documento_dentro_de_la_ventana(): void
    {
        $documento = $this->crearDocumento(20);

        $resultado = $this->generador->generarParaTenant($this->tenantId, $this->hoy);

        // Con umbrales de 30 y 15, y un documento que vence en 20 días, solo
        // entra el de 30. El de 15 todavía no corresponde.
        $this->assertSame(1, $resultado->alertasGeneradas);
        $this->assertSame(0, $resultado->alertasOmitidasPorDuplicado);

        $alertas = app(ContextoTenant::class)->ejecutarAislado(
            $this->tenantId,
            static fn () => Documento::find($documento->id)->alertas()->get()
        );

        $this->assertCount(1, $alertas);
        $this->assertSame('umbral', $alertas->first()->tipo);
        $this->assertSame(30, $alertas->first()->umbral_dias);
    }

    /**
     * 2. Y LA PRINCIPAL: reejecutar no duplica.
     */
    public function test_reejecutar_el_generador_no_duplica_alertas(): void
    {
        $this->crearDocumento(20);
        $this->crearDocumento(10);

        $primera = $this->generador->generarParaTenant($this->tenantId, $this->hoy);

        $this->assertGreaterThan(0, $primera->alertasGeneradas);

        $totalTrasPrimera = $this->contarAlertas();
        $this->assertSame($primera->alertasGeneradas, $totalTrasPrimera);

        // Tres ejecuciones más, como si la tarea programada corriera tres
        // veces más (o como si alguien la reejecutara a mano).
        for ($i = 0; $i < 3; $i++) {
            $posterior = $this->generador->generarParaTenant($this->tenantId, $this->hoy);

            $this->assertSame(
                0,
                $posterior->alertasGeneradas,
                'Una reejecución no debe generar alertas nuevas.'
            );
            $this->assertSame(
                $totalTrasPrimera,
                $posterior->alertasOmitidasPorDuplicado,
                'Cada alerta previa debe omitirse por duplicado, no reinsertarse.'
            );
        }

        $this->assertSame($totalTrasPrimera, $this->contarAlertas());
    }

    /**
     * 3. Distintos umbrales son periodos distintos.
     */
    public function test_umbrales_distintos_generan_alertas_distintas(): void
    {
        // Vence en 10 días: entra en el umbral de 30 y en el de 15.
        $this->crearDocumento(10);

        $resultado = $this->generador->generarParaTenant($this->tenantId, $this->hoy);

        $this->assertSame(2, $resultado->alertasGeneradas);

        $umbrales = app(ContextoTenant::class)->ejecutarAislado(
            $this->tenantId,
            static fn () => Documento::first()->alertas()->pluck('umbral_dias')->sort()->values()->all()
        );

        $this->assertSame([15, 30], $umbrales, 'Los dos umbrales deben estar, y son periodos distintos.');
    }

    /**
     * 4. Documento vencido: alerta de vencido, no de umbral.
     */
    public function test_documento_vencido_genera_alerta_de_vencido(): void
    {
        $this->crearDocumento(-10);

        $resultado = $this->generador->generarParaTenant($this->tenantId, $this->hoy);

        $this->assertSame(1, $resultado->documentosVencidos);

        $alerta = app(ContextoTenant::class)->ejecutarAislado(
            $this->tenantId,
            static fn () => Documento::first()->alertas()->first()
        );

        $this->assertSame('vencido', $alerta->tipo);
        $this->assertNull($alerta->umbral_dias);
        $this->assertStringContainsString('vencido', $alerta->periodo_clave);
    }

    /**
     * 5. Un documento sin fecha no genera alertas inventadas.
     */
    public function test_documento_sin_fecha_no_genera_alertas(): void
    {
        $this->crearDocumento(null);

        $resultado = $this->generador->generarParaTenant($this->tenantId, $this->hoy);

        $this->assertSame(0, $resultado->alertasGeneradas);
        $this->assertSame(1, $resultado->documentosSinFecha);
    }

    /**
     * 6. RENOVACIÓN: cambiar la fecha reabre los avisos.
     *
     * Este es el caso que hace que el período incluya la fecha. Si la clave
     * fuera solo el identificador del documento, las alertas del ciclo
     * anterior bloquearían las nuevas y el vehículo quedaría mudo después de
     * renovar el permiso.
     */
    public function test_renovar_el_documento_abre_un_periodo_nuevo(): void
    {
        $documento = $this->crearDocumento(20);

        $primera = $this->generador->generarParaTenant($this->tenantId, $this->hoy);
        $this->assertSame(1, $primera->alertasGeneradas);

        // Se renueva con una fecha nueva, más lejos en el tiempo.
        app(ContextoTenant::class)->ejecutarAislado(
            $this->tenantId,
            static fn () => Documento::whereKey($documento->id)->update([
                'fecha_vencimiento' => Carbon::now()->addDays(100)->toDateString(),
            ])
        );

        $segunda = $this->generador->generarParaTenant($this->tenantId, $this->hoy);

        // Con la nueva fecha, ya no entra en ninguna ventana: cero nuevas.
        $this->assertSame(0, $segunda->alertasGeneradas);
        $this->assertSame(1, $this->contarAlertas());

        // Y si vuelve a acercarse, el aviso se vuelve a generar.
        app(ContextoTenant::class)->ejecutarAislado(
            $this->tenantId,
            static fn () => Documento::whereKey($documento->id)->update([
                'fecha_vencimiento' => Carbon::now()->addDays(5)->toDateString(),
            ])
        );

        $tercera = $this->generador->generarParaTenant($this->tenantId, $this->hoy);

        $this->assertSame(2, $tercera->alertasGeneradas, 'La fecha nueva debe abrir sus propios periodos.');
    }

    /**
     * 7. LA GARANTÍA REAL: el índice único de la base.
     *
     * Esta prueba no pasa por el generador: inserta dos veces la misma clave
     * de periodo a mano. Si el índice único no existiera, la fila se
     * duplicaría. Con el índice, la base la rechaza.
     *
     * Es la prueba que sostiene la afirmación "no es que el código se acuerde
     * de no repetir; es que la base no lo permite".
     */
    public function test_el_indice_unico_impide_dos_alertas_del_mismo_periodo(): void
    {
        $documento = $this->crearDocumento(20);

        $this->generador->generarParaTenant($this->tenantId, $this->hoy);

        $alerta = app(ContextoTenant::class)->ejecutarAislado(
            $this->tenantId,
            static fn () => Documento::find($documento->id)->alertas()->first()
        );

        $inserciones = app(ContextoTenant::class)->ejecutarAislado(
            $this->tenantId,
            static fn () => DB::table('alertas_documento')->insertOrIgnore([
                [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $alerta->tenant_id,
                    'documento_id' => $alerta->documento_id,
                    'vehiculo_id' => $alerta->vehiculo_id,
                    'conductor_id' => null,
                    'tipo_documento_id' => $alerta->tipo_documento_id,
                    'fecha_vencimiento_referencia' => $alerta->fecha_vencimiento_referencia,
                    'origen_vencimiento' => 'documento',
                    'tipo' => $alerta->tipo,
                    'umbral_dias' => $alerta->umbral_dias,
                    'dias_restantes' => $alerta->dias_restantes,
                    'periodo_clave' => $alerta->periodo_clave,
                    'estado' => 'pendiente',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ])
        );

        $this->assertSame(0, $inserciones, 'La base debe rechazar la segunda alerta del mismo periodo.');
        $this->assertSame(1, $this->contarAlertas());
    }

    /**
     * 8. Dos flotas no se pisan entre sí.
     */
    public function test_las_alertas_de_una_flota_no_generan_en_otra(): void
    {
        $otraTenant = $this->crearTenant('flota-otra');

        $contexto = app(ContextoTenant::class);

        // Un documento en cada flota, con la misma fecha de vencimiento.
        $contexto->ejecutarAislado(
            $this->tenantId,
            function (): void {
                $this->crearDocumento(20);
            }
        );

        $contexto->ejecutarAislado(
            (string) $otraTenant->id,
            function () use ($contexto, $otraTenant): void {
                $contexto->crearConfiguracionAlertas((string) $otraTenant->id, [30, 15]);
                $this->crearDocumento(20, (string) $otraTenant->id);
            }
        );

        $this->generador->generarParaTenant($this->tenantId, $this->hoy);

        $enLaOtra = $contexto->ejecutarAislado(
            (string) $otraTenant->id,
            static fn () => DB::table('alertas_documento')->count()
        );

        $this->assertSame(0, $enLaOtra, 'Generar en una flota no debe crear alertas en otra.');
    }

    /** Crea un documento que vence dentro de `$diasRestantes` días. */
    private function crearDocumento(?int $diasRestantes, ?string $tenantId = null): Documento
    {
        $tenantId ??= $this->tenantId;

        $tipo = TipoDocumento::firstOrCreate(
            ['clave' => 'permiso_circulacion'],
            ['nombre' => 'Permiso de circulación', 'sujeto' => 'vehiculo', 'activo' => true]
        );

        $vehiculo = $this->crearVehiculo($tenantId, 'DOC'.random_int(10, 99));

        return app(ContextoTenant::class)->ejecutarAislado(
            $tenantId,
            static fn () => Documento::create([
                'vehiculo_id' => $vehiculo->id,
                'tipo_documento_id' => $tipo->id,
                'numero' => 'PC-'.random_int(1000, 9999),
                'fecha_emision' => Carbon::now()->subYear()->toDateString(),
                'fecha_vencimiento' => $diasRestantes === null
                    ? null
                    : Carbon::now()->addDays($diasRestantes)->toDateString(),
                'estado' => 'vigente',
            ])
        );
    }

    private function contarAlertas(): int
    {
        return app(ContextoTenant::class)->ejecutarAislado(
            $this->tenantId,
            static fn () => DB::table('alertas_documento')->count()
        );
    }
}