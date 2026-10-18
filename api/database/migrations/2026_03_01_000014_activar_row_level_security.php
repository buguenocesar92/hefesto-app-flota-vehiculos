<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AISLAMIENTO ENTRE FLOTAS: Row Level Security (RLS) de PostgreSQL.
 *
 * Esta migracion es el corazon de la seguridad del producto. Ver
 * docs/03-MULTITENANCY-Y-ROLES.md para el analisis de por que se eligio RLS
 * y no esquema por flota. El patron es el mismo que usa
 * `apps/portal-autoservicio`, a proposito: una sola convencion en Hefesto.
 *
 * MECANISMO
 * ---------
 * 1. `public.app_tenant_id()` lee la variable de sesion `app.tenant_id`. Si
 *    no esta definida, LANZA un error. No devuelve NULL ni cadena vacia: el
 *    aislamiento falla cerrado, nunca abierto.
 * 2. Cada tabla de negocio tiene una politica que exige
 *    `tenant_id = app_tenant_id()`, al leer (USING) y al escribir
 *    (WITH CHECK).
 * 3. `FORCE ROW LEVEL SECURITY` hace que la politica aplique tambien al
 *    dueno de la tabla. Sin FORCE, el dueno la saltaria, y en desarrollo el
 *    rol de la aplicacion suele ser el dueno: el aislamiento seria
 *    decorativo justo donde se prueba.
 * 4. El tenant viaja con `set_config('app.tenant_id', ..., true)`, es decir
 *    con alcance de TRANSACCION. Al confirmar o revertir, PostgreSQL la
 *    descarta. Con un `SET` de sesion, el worker de colas, que reutiliza la
 *    conexion entre trabajos de flotas distintas, podria leer datos de otra
 *    flota. Ese es el fallo que este diseno evita.
 *
 * LA TABLA `tenants` NO LLEVA RLS
 * ------------------------------
 * Se consulta durante el login, cuando todavia no hay sesion y por lo tanto
 * no hay `app.tenant_id`. Es una excepcion consciente, compensada por la
 * vista `vw_tenants_publicos` (migracion 2026_03_01_000015), que expone solo
 * tres columnas y solo de las flotas activas.
 *
 * `tipos_documento` Y `configuraciones_alerta`
 * -------------------------------------------
 * `tipos_documento` es el catalogo nacional y NO lleva `tenant_id` ni RLS:
 * no contiene ningun dato de ninguna flota (ver el comentario de su
 * migracion). `configuraciones_alerta` SI lleva `tenant_id` y SI lleva RLS:
 * ahi vive la decision del cliente sobre cuantos dias antes quiere avisos.
 *
 * REQUISITO DE DESPLIEGUE
 * -----------------------
 * El rol que usa la aplicacion NO puede ser superusuario ni tener BYPASSRLS.
 * Con superusuario, PostgreSQL ignora las politicas por completo. El
 * comando `php artisan flota:verificar-aislamiento` lo comprueba y falla.
 */
return new class extends Migration
{
    /**
     * Tablas con datos de negocio, y por lo tanto con RLS.
     *
     * @var list<string>
     */
    private array $tablasProtegidas = [
        'usuarios',
        'tipos_servicio',
        'centros_costo',
        'talleres',
        'conductores',
        'vehiculos',
        'configuraciones_alerta',
        'documentos',
        'reglas_frecuencia_documento',
        'mantenciones',
        'asignaciones',
        'viajes',
        'cargas_combustible',
        'lecturas_kilometraje',
        'alertas_documento',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                valor := current_setting('app.tenant_id', true);

                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'insufficient_privilege',
                        MESSAGE = 'No hay contexto de tenant en la conexion (app.tenant_id no esta definida). Se aborta la operacion para no exponer datos de otra flota.',
                        HINT = 'La peticion no paso por App\Http\Middleware\ResolverTenant, o la operacion se ejecuto fuera de una transaccion.';
                END IF;

                RETURN valor::uuid;
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON FUNCTION public.app_tenant_id() IS
                'Devuelve el tenant de la peticion. Lanza excepcion si no hay contexto: el aislamiento falla cerrado.';
        SQL);

        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne(
                'SELECT to_regclass(?) AS tabla',
                ['public.'.$tabla]
            );

            // to_regclass devuelve NULL si la tabla todavia no existe.
            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('ALTER TABLE public.'.$tabla.' ENABLE ROW LEVEL SECURITY');

            // FORCE hace que la politica aplique tambien al dueno de la
            // tabla. Es indispensable en desarrollo, donde el rol de la
            // aplicacion suele ser el mismo que creo las tablas.
            DB::unprepared('ALTER TABLE public.'.$tabla.' FORCE ROW LEVEL SECURITY');

            $nombre = 'politica_aislamiento_'.$tabla;

            DB::unprepared(<<<SQL
                DROP POLICY IF EXISTS {$nombre} ON public.{$tabla};

                CREATE POLICY {$nombre} ON public.{$tabla}
                    FOR ALL
                    TO PUBLIC
                    USING (tenant_id = public.app_tenant_id())
                    WITH CHECK (tenant_id = public.app_tenant_id());
            SQL);
        }

        // Falla ruidosamente si alguna tabla de la lista no tiene
        // `tenant_id`: un error de tipeo dejaria datos de negocio sin
        // politica y la migracion pasaria igual.
        $this->verificarColumnaTenant();
    }

    public function down(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne('SELECT to_regclass(?) AS tabla', ['public.'.$tabla]);

            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('DROP POLICY IF EXISTS politica_aislamiento_'.$tabla.' ON public.'.$tabla);
            DB::unprepared('ALTER TABLE public.'.$tabla.' NO FORCE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE public.'.$tabla.' DISABLE ROW LEVEL SECURITY');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS public.app_tenant_id()');
    }

    private function verificarColumnaTenant(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne(<<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = ? AND column_name = 'tenant_id'
                 ) AS tiene_columna
            SQL, [$tabla]);

            if ($existe === null || ! $existe->tiene_columna) {
                throw new RuntimeException(
                    'La tabla public.'.$tabla.' no tiene columna tenant_id y no puede protegerse con RLS. '
                    .'Revise la migracion antes de continuar.'
                );
            }
        }
    }
};