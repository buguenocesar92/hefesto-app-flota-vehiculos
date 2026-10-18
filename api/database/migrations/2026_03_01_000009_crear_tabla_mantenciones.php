<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `mantenciones`.
 *
 * Es un modulo de apoyo, no el centro del producto. El centro son los
 * vencimientos. Pero una flota sin historial de mantenciones no puede
 * responder si el vehiculo esta bien cuidado.
 *
 * UNA MANTENCION PUEDE MEDIR POR KILOMETRAJE, POR FECHA O POR LAS DOS COSAS.
 * El servicio de mantenimiento (cambio de aceite, revision de frenos) se
 * agenda por kilometraje; la revision periodica de seguridad, por fecha.
 * `App\Enums\TipoMantencion` distingue los tres casos y la validacion exige
 * el dato que corresponde a cada uno.
 *
 * `km_al_entregar` es la lectura que el taller informo. Es lo que genera una
 * fila en `lecturas_kilometraje` con `fuente = 'mantencion'`, de modo que el
 * kilometraje del vehiculo tenga en cuenta lo que dijo el taller y no solo lo
 * que dijo el chofer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mantenciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $tabla->foreignUuid('vehiculo_id')->constrained('vehiculos')->cascadeOnDelete();

            $tabla->foreignUuid('taller_id')
                ->nullable()
                ->constrained('talleres')
                ->nullOnDelete();

            // 'programada' | 'en_proceso' | 'completada' | 'cancelada'
            $tabla->string('estado', 24)->default('programada');

            // 'kilometraje' | 'fecha' | 'mixto'
            $tabla->string('tipo', 24)->default('kilometraje');

            $tabla->string('descripcion', 255);

            // Programacion.
            $tabla->unsignedInteger('km_programado')->nullable();
            $tabla->date('fecha_programada')->nullable();

            // Ejecucion real.
            $tabla->date('fecha_ejecucion')->nullable();
            $tabla->unsignedInteger('km_al_entregar')->nullable();

            // Costo en pesos chilenos. Numeric, no float: con float, una
            // suma de muchas mantenciones deja centimos de diferencia.
            $tabla->decimal('costo', 14, 0)->nullable();

            // Proxima mantencion sugerida al terminar esta. Permite encadenar
            // el plan sin que nadie tenga que calcularlo de cabeza.
            $tabla->unsignedInteger('km_proximo')->nullable();
            $tabla->date('fecha_proxima')->nullable();

            $tabla->text('observaciones')->nullable();

            $tabla->foreignUuid('usuario_registro_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete();

            $tabla->timestampsTz();

            // Indice que sostiene el tablero de "que hay que hacer pronto".
            $tabla->index(['tenant_id', 'estado', 'fecha_programada']);
            $tabla->index(['tenant_id', 'vehiculo_id', 'km_programado']);

            // Una mantencion por fecha o por kilometraje tiene que decir en
            // cual de las dos se programo. Sin esto, el tablero no puede
            // ordenar nada.
            DB::unprepared(<<<'SQL'
                ALTER TABLE public.mantenciones
                ADD CONSTRAINT mantenciones_declara_su_programacion
                CHECK (
                    tipo = 'kilometraje' AND km_programado IS NOT NULL
                    OR tipo = 'fecha' AND fecha_programada IS NOT NULL
                    OR tipo = 'mixto' AND (km_programado IS NOT NULL OR fecha_programada IS NOT NULL)
                );
            SQL);

            DB::unprepared(<<<'SQL'
                ALTER TABLE public.mantenciones
                ADD CONSTRAINT mantenciones_ejecutada_tiene_fecha
                CHECK (estado <> 'completada' OR fecha_ejecucion IS NOT NULL);
            SQL);

            // Un vehiculo no puede tener dos mantenciones en el taller a la
            // vez. Sin esta restriccion, el tablero muestra dos
            // "en_proceso" para el mismo vehiculo y nadie sabe cual cuenta.
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX mantenciones_una_en_proceso
                ON public.mantenciones (vehiculo_id)
                WHERE estado = 'en_proceso';
            SQL);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mantenciones');
    }
};