<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comprueba que el aislamiento entre flotas está realmente activo.
 *
 * ESTE COMANDO ES UNA DEFENSA, NO UN ADORNO
 * ----------------------------------------
 * Toda la seguridad del producto descansa en que PostgreSQL aplique la Row
 * Level Security. Hay dos formas de que eso deje de ser cierto sin que nadie
 * se dé cuenta:
 *
 * 1. Que el rol de la aplicación sea superusuario, o tenga BYPASSRLS. Con
 *    superusuario, PostgreSQL ignora las políticas por completo y devuelve
 *    todas las filas de todas las flotas. La aplicación seguiría
 *    "funcionando" y el fallo sería invisible.
 *
 * 2. Que una tabla nueva se quede sin política por un error de tipeo al
 *    agregarla a la lista.
 *
 * Por eso este comando se ejecuta en el despliegue, y falla ruidosamente si
 * encuentra cualquiera de los dos casos.
 *
 * Uso: `php artisan flota:verificar-aislamiento`
 */
class VerificarAislamientoCommand extends Command
{
    protected $signature = 'flota:verificar-aislamiento';

    protected $description = 'Verifica que la Row Level Security esté activa y que el rol no la pueda saltar.';

    public function handle(): int
    {
        $fallos = [];

        $fallos = array_merge($fallos, $this->revisarRol());
        $fallos = array_merge($fallos, $this->revisarPoliticas());
        $fallos = array_merge($fallos, $this->revisarFuncionDeContexto());

        if ($fallos === []) {
            $this->info('Aislamiento verificado: Row Level Security activa y sin vías para saltarla.');

            return self::SUCCESS;
        }

        $this->error('El aislamiento entre flotas NO está garantizado:');
        $this->newLine();

        foreach ($fallos as $fallo) {
            $this->line('  - '.$fallo);
        }

        $this->newLine();
        $this->line('No publiques el sistema en producción hasta corregir esto.');

        return self::FAILURE;
    }

    /**
     * El rol de la aplicación no puede ser superusuario ni tener BYPASSRLS.
     *
     * @return list<string>
     */
    private function revisarRol(): array
    {
        $fallos = [];

        $actual = DB::selectOne('SELECT current_user AS usuario');

        $privilegios = DB::selectOne(<<<'SQL'
            SELECT
                r.rolsuper AS es_superusuario,
                r.rolbypassrls AS salta_rls
            FROM pg_roles r
            WHERE r.rolname = current_user
        SQL);

        if ($privilegios === null) {
            return ['No se pudo leer el rol de la base de datos.'];
        }

        if ((bool) $privilegios->es_superusuario) {
            $fallos[] = sprintf(
                'El rol «%s» es superusuario: PostgreSQL ignora TODAS las políticas de RLS. '
                .'Cree un rol de aplicación sin privilegios de superusuario.',
                $actual->usuario
            );
        }

        if ((bool) $privilegios->salta_rls) {
            $fallos[] = sprintf(
                'El rol «%s» tiene BYPASSRLS: se salta las políticas de RLS. '
                .'Revise el rol con «ALTER ROLE ... NOBYPASSRLS».',
                $actual->usuario
            );
        }

        return $fallos;
    }

    /**
     * Toda tabla de negocio tiene RLS activa y con FORCE.
     *
     * @return list<string>
     */
    private function revisarPoliticas(): array
    {
        $fallos = [];

        $tablas = DB::select(<<<'SQL'
            SELECT c.relname AS tabla,
                   c.relrowsecurity AS rls_activada,
                   c.relforcerowsecurity AS rls_forzada
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND EXISTS (
                  SELECT 1 FROM pg_attribute a
                  WHERE a.attrelid = c.oid
                    AND a.attname = 'tenant_id'
                    AND NOT a.attisdropped
              )
              AND c.relname <> 'tenants'
            ORDER BY c.relname
        SQL);

        if ($tablas === []) {
            return ['No se encontró ninguna tabla con «tenant_id». ¿Se ejecutaron las migraciones?'];
        }

        foreach ($tablas as $tabla) {
            if (! $tabla->rls_activada) {
                $fallos[] = sprintf('La tabla «%s» no tiene Row Level Security activada.', $tabla->tabla);

                continue;
            }

            if (! $tabla->rls_forzada) {
                $fallos[] = sprintf(
                    'La tabla «%s» tiene RLS sin FORCE: el dueño de la tabla la puede saltar.',
                    $tabla->tabla
                );
            }
        }

        return $fallos;
    }

    /**
     * La función de contexto tiene que seguir fallando cerrado.
     *
     * @return list<string>
     */
    private function revisarFuncionDeContexto(): array
    {
        $existe = DB::selectOne("SELECT to_regprocedure('public.app_tenant_id()') AS funcion");

        if ($existe === null || $existe->funcion === null) {
            return ['No existe la función public.app_tenant_id(). ¿Se ejecutaron las migraciones?'];
        }

        // Se intenta leer sin contexto de tenant. Si en vez de fallar
        // devolviera filas, el aislamiento estaría abierto y hay que saberlo.
        try {
            DB::statement("SELECT set_config('app.tenant_id', '', false)");

            DB::select('SELECT count(*) FROM public.vehiculos');
        } catch (\Throwable) {
            return [];
        }

        return [
            'La función public.app_tenant_id() NO lanza excepción cuando falta el contexto de tenant. '
            .'Una consulta sin flota podría devolver datos de todas las flotas. Revise la migración de RLS.',
        ];
    }
}