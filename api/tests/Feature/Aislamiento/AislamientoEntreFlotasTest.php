<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Documento;
use App\Models\TipoDocumento;
use App\Models\Vehiculo;
use App\Services\Tenancy\ContextoTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AISLAMIENTO ENTRE FLOTAS.
 *
 * Requisito de seguridad número uno: una flota no puede ver ni tocar los
 * datos de otra. El dueño de una flota no tiene por qué conocer la existencia
 * de otra, y menos todavía sus patentes.
 *
 * DOS NIVELES, Y ESTA PRUEBA LOS COMPRUEBA A LOS DOS
 * ---------------------------------------------------
 *
 * 1. NIVEL DE APLICACIÓN: la Policy y el filtro por tenant. Se puede
 *    comprobar en cualquier motor.
 *
 * 2. NIVEL DE BASE: la Row Level Security de PostgreSQL. Una consulta sin
 *    contexto de tenant tiene que FALLAR, no devolver filas.
 *
 * El segundo nivel NO se puede probar en SQLite, porque SQLite no tiene RLS.
 * Por eso las pruebas que lo necesitan se saltan solas con
 * `requierePostgres()`, y por eso este archivo dice explícitamente qué se
 * comprobó con qué motor. Nada se declara probado sin haberlo ejecutado.
 */
class AislamientoEntreFlotasTest extends TestCase
{
    private string $tenantA;

    private string $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = (string) $this->crearTenant('flota-a')->id;
        $this->tenantB = (string) $this->crearTenant('flota-b')->id;
    }

    /**
     * Un vehículo de la flota A no aparece en la lista de la flota B.
     */
    public function test_un_vehiculo_de_otra_flota_no_aparece_en_el_listado(): void
    {
        $vehiculoA = $this->crearVehiculo($this->tenantA, 'AAA-11');
        $vehiculoB = $this->crearVehiculo($this->tenantB, 'BBB-22');

        $vistosPorB = $this->comoTenant($this->tenantB, static fn () => Vehiculo::all()->pluck('id'));

        $this->assertTrue($vistosPorB->contains($vehiculoB->id));
        $this->assertFalse($vistosPorB->contains($vehiculoA->id));
    }

    /**
     * Un vehículo de la flota A no es visible en el detalle, ni siquiera
     * pidiendo su identificador directamente.
     */
    public function test_no_se_puede_leer_un_vehiculo_de_otra_flota_por_identificador(): void
    {
        $vehiculoA = $this->crearVehiculo($this->tenantA, 'AAA-33');

        $this->requierePostgres('El detalle por identificador depende de la RLS.');

        $this->comoTenant($this->tenantB, static function () use ($vehiculoA): void {
            $this->assertNull(
                Vehiculo::query()->find($vehiculoA->id),
                'La RLS debe ocultar el vehículo de la otra flota.'
            );
        });
    }

    /**
     * Los documentos tampoco cruzan.
     */
    public function test_los_documentos_no_cruzan_de_flota(): void
    {
        $vehiculoA = $this->crearVehiculo($this->tenantA, 'AAA-44');
        $vehiculoB = $this->crearVehiculo($this->tenantB, 'BBB-55');

        $this->crearDocumento($vehiculoA, $this->tenantA);
        $this->crearDocumento($vehiculoB, $this->tenantB);

        $vistosPorB = $this->comoTenant($this->tenantB, static fn () => Documento::count());

        $this->assertSame(1, $vistosPorB, 'Cada flota debe ver solo sus propios documentos.');
    }

    /**
     * UNA CONSULTA SIN TENANT TIENE QUE FALLAR.
     *
     * Esta es la prueba que importa de verdad en PostgreSQL. La función
     * `public.app_tenant_id()` LANZA excepción cuando no hay contexto, y esa
     * excepción es lo que impide que un error de programación devuelva datos
     * de todas las flotas.
     */
    public function test_una_consulta_sin_contexto_de_tenant_falla_cerrado(): void
    {
        $this->requierePostgres('La función de contexto de tenant solo existe en PostgreSQL.');

        $this->crearVehiculo($this->tenantA, 'AAA-66');

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Sin `ejecutarAislado`: no hay `app.tenant_id` en la conexión.
        Vehiculo::query()->count();
    }

    /**
     * La función de contexto se puede auditar a mano.
     */
    public function test_la_funcion_de_contexto_devuelve_el_tenant_fijado(): void
    {
        $this->requierePostgres();

        $devuelto = $this->comoTenant(
            $this->tenantA,
            static fn () => DB::selectOne('SELECT public.app_tenant_id() AS tenant_id')->tenant_id
        );

        $this->assertSame($this->tenantA, $devuelto);
    }

    /**
     * ESCRIBIR en nombre de otra flota también se impide.
     *
     * Con la política equivocada, se podría crear una fila en la flota ajena
     * que quedaría invisible para su dueño: una fila espuria que nadie ve y
     * nadie borra. `WITH CHECK` existe justo para eso.
     */
    public function test_no_se_puede_escribir_en_otra_flota(): void
    {
        $this->requierePostgres('WITH CHECK es una restricción de PostgreSQL.');

        $vehiculoA = $this->crearVehiculo($this->tenantA, 'AAA-77');

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->comoTenant($this->tenantB, static function () use ($vehiculoA): void {
            DB::table('vehiculos')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $vehiculoA->tenant_id,
                'patente' => 'HACK-01',
                'marca' => 'X',
                'modelo' => 'Y',
                'anio' => 2020,
                'tipo_servicio_id' => DB::table('tipos_servicio')->value('id'),
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Ejecuta algo con el tenant fijado, como lo haría el middleware.
     *
     * @template T
     *
     * @param  \Closure(): T  $operacion
     * @return T
     */
    private function comoTenant(string $tenantId, \Closure $operacion): mixed
    {
        return app(ContextoTenant::class)->ejecutarAislado($tenantId, $operacion);
    }

    private function crearDocumento(Vehiculo $vehiculo, string $tenantId): Documento
    {
        $tipo = TipoDocumento::firstOrCreate(
            ['clave' => 'permiso_circulacion'],
            ['nombre' => 'Permiso de circulación', 'sujeto' => 'vehiculo', 'activo' => true]
        );

        return $this->comoTenant($tenantId, static fn () => Documento::create([
            'vehiculo_id' => $vehiculo->id,
            'tipo_documento_id' => $tipo->id,
            'numero' => 'PC-'.Str::random(6),
            'fecha_emision' => Carbon::now()->subYear()->toDateString(),
            'fecha_vencimiento' => Carbon::now()->addDays(40)->toDateString(),
            'estado' => 'vigente',
        ]));
    }
}