<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `tenants`: una flota.
 *
 * NO lleva Row Level Security a proposito. Se consulta durante el login,
 * cuando todavia no hay sesion y por lo tanto no existe `app.tenant_id` que
 * poner en la conexion. La compensacion es la vista `vw_tenants_publicos`
 * (migracion 2026_03_01_000015), que expone solo tres columnas y solo de las
 * flotas activas. Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();

            // Identificador corto y legible que puede aparecer en la URL de
            // entrada y en los enlaces que la flota reparte.
            $tabla->string('slug', 64)->unique();

            $tabla->string('nombre', 160);
            $tabla->string('razon_social', 200)->nullable();

            // RUT chileno. Se guarda como texto y no como numero: el RUT
            // termina en un digito verificador que puede ser una letra (K),
            // y formatearlo como entero pierde informacion.
            $tabla->string('rut', 20)->nullable();

            $tabla->string('correo_contacto')->nullable();
            $tabla->string('telefono', 32)->nullable();

            $tabla->boolean('activo')->default(true);

            // Zona horaria propia de la flota, por si opera en-region.
            $tabla->string('zona_horaria', 64)->default('America/Santiago');

            // Momento del ultimo calculo de alertas, para que la pantalla
            // pueda mostrar "avisos actualizados hace 6 horas" en vez de dar
            // la impression de que esta al dia.
            $tabla->timestamp('alertas_generadas_at')->nullable();

            $tabla->timestampsTz();

            $tabla->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};