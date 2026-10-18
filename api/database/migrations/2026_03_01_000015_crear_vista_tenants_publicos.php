<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista `vw_tenants_publicos`: la compensacion por `tenants` no tener RLS.
 *
 * `tenants` es la unica tabla que se consulta antes del login, cuando no hay
 * `app.tenant_id` que poner en la conexion. Por eso no lleva politica de
 * aislamiento.
 *
 * Esta vista reduce lo que se puede leer de ella: TRES columnas, y solo de
 * las flotas activas. No expone RUT, razon social, correos ni telefonos.
 *
 * Que un `slug` se pueda enumerar antes de autenticarse es un riesgo
 * consciente y documentado: el slug aparece en la URL de entrada y en los
 * enlaces que la flota reparte. Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE VIEW public.vw_tenants_publicos AS
            SELECT
                slug,
                nombre,
                activo
            FROM public.tenants
            WHERE activo = true;
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON VIEW public.vw_tenants_publicos IS
                'Unica via de lectura de tenants antes del login. Expone slug, nombre y activo, y nada mas.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.vw_tenants_publicos');
    }
};