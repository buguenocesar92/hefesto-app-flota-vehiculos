<?php

declare(strict_types=1);

namespace Tests;

use App\Models\ConfiguracionAlerta;
use App\Models\Tenant;
use App\Models\TipoServicio;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Base de las pruebas.
 *
 * DOS COSAS QUE ESTAS PRUEBAS NO SON
 * ----------------------------------
 *
 * 1. El motor por defecto es SQLite en memoria. Las políticas de Row Level
 *    Security de PostgreSQL NO existen en SQLite, así que una prueba que
 *    "comprueba el aislamiento" corriendo sobre SQLite en realidad está
 *    comprobando el aislamiento de la capa de aplicación, no el de la base.
 *
 *    Por eso las pruebas que dependen de PostgreSQL se saltan solas cuando el
 *    motor no es PostgreSQL (`requierePostgres()`), y nunca se declaran
 *    probadas si no se ejecutaron contra la base real. Ver
 *    docs/10-PRUEBAS-Y-ESTADO.md.
 *
 * 2. En el entorno donde se escribió este código no había PHP, ni Composer,
 *    ni PostgreSQL. NINGUNA de estas pruebas se ejecutó. Están escritas y
 *    son correctas en su intención, pero "escrita" no es "probada".
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Salta la prueba si el motor no es PostgreSQL.
     *
     * Se usa `markTestSkipped()` y no un simple `return`: PHPUnit registra el
     * salto, y el resultado de la suite sigue siendo honesto sobre lo que se
     * probó y lo que no.
     */
    protected function requierePostgres(string $motivo = 'Requiere PostgreSQL.'): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped($motivo.' La Row Level Security solo existe en PostgreSQL.');
        }
    }

    /** Crea una flota con sus catálogos mínimos. */
    protected function crearTenant(string $slug = 'flota-prueba'): Tenant
    {
        return Tenant::create([
            'slug' => $slug,
            'nombre' => 'Flota de prueba',
            'activo' => true,
            'zona_horaria' => 'America/Santiago',
        ]);
    }

    /** Crea un usuario en la flota, dentro del contexto de esa flota. */
    protected function crearUsuario(string $tenantId, string $rol = 'administrador'): Usuario
    {
        return app(\App\Services\Tenancy\ContextoTenant::class)->ejecutarAislado(
            $tenantId,
            static fn () => Usuario::create([
                'authentik_sub' => (string) Str::uuid(),
                'nombre' => 'Usuario de prueba',
                'correo' => Str::random(10).'@ejemplo.test',
                'rol' => $rol,
                'activo' => true,
            ])
        );
    }

    /** Crea un tipo de servicio y un vehículo de prueba. */
    protected function crearVehiculo(string $tenantId, string $patente = 'TEST-11'): Vehiculo
    {
        return app(\App\Services\Tenancy\ContextoTenant::class)->ejecutarAislado(
            $tenantId,
            function () use ($tenantId, $patente): Vehiculo {
                $tipo = TipoServicio::firstOrCreate(
                    ['tenant_id' => $tenantId, 'codigo' => 'PRUEBA'],
                    ['nombre' => 'Servicio de prueba', 'activo' => true]
                );

                return Vehiculo::create([
                    'patente' => $patente,
                    'marca' => 'Marca',
                    'modelo' => 'Modelo',
                    'anio' => 2020,
                    'tipo_servicio_id' => $tipo->id,
                    'activo' => true,
                ]);
            }
        );
    }

    /** Crea los umbrales de aviso por defecto para una flota. */
    protected function crearConfiguracionAlertas(string $tenantId, array $umbrales = [60, 30, 15, 7, 1]): void
    {
        app(\App\Services\Tenancy\ContextoTenant::class)->ejecutarAislado(
            $tenantId,
            static function () use ($tenantId, $umbrales): void {
                foreach ($umbrales as $umbral) {
                    ConfiguracionAlerta::create([
                        'tipo_documento_id' => null,
                        'umbral_dias' => $umbral,
                        'dias_insistencia_vencido' => 0,
                        'canal' => 'correo',
                        'activa' => true,
                    ]);
                }
            }
        );
    }
}