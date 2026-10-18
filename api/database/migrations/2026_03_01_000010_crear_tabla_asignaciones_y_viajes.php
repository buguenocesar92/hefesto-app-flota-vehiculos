<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas `asignaciones` y `viajes`.
 *
 * La pregunta que responde esto es "que vehiculo esta con que conductor, y
 * desde cuando". El indice unico parcial hace que esa pregunta tenga una
 * sola respuesta posible:
 *
 *   - un vehiculo no puede estar asignado a dos conductores a la vez;
 *   - un conductor no puede estar asignado a dos vehiculos a la vez.
 *
 * No es una validacion del formulario: es una restriccion de la base. Si dos
 * personas abren el mismo vehiculo al mismo tiempo y las dos guardan, la
 * segunda operacion falla. Sin esto, el historial de asignaciones muestra
 * dos conductoresconductores usando la misma camioneta el mismo dia, y a fin
 * de mes nadie sabe a quien cargarle el combustible.
 *
 * `hasta` en NULL significa "asignacion abierta": sigue vigente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $tabla->foreignUuid('vehiculo_id')->constrained('vehiculos')->cascadeOnDelete();
            $tabla->foreignUuid('conductor_id')->constrained('conductores')->cascadeOnDelete();

            $tabla->date('desde');
            $tabla->date('hasta')->nullable();

            $tabla->string('motivo', 255)->nullable();
            $tabla->text('observaciones')->nullable();

            $tabla->foreignUuid('usuario_registro_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete();

            $tabla->timestampsTz();

            // No se puede cerrar una asignacion antes de empezarla.
            DB::unprepared(<<<'SQL'
                ALTER TABLE public.asignaciones
                ADD CONSTRAINT asignaciones_rango_coherente
                CHECK (hasta IS NULL OR hasta >= desde);
            SQL);

            $tabla->index(['tenant_id', 'vehiculo_id', 'desde']);
            $tabla->index(['tenant_id', 'conductor_id', 'desde']);

            // Una asignacion abierta por vehiculo.
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX asignaciones_una_abierta_por_vehiculo
                ON public.asignaciones (vehiculo_id)
                WHERE hasta IS NULL;
            SQL);

            // Una asignacion abierta por conductor.
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX asignaciones_una_abierta_por_conductor
                ON public.asignaciones (conductor_id)
                WHERE hasta IS NULL;
            SQL);
        });

        Schema::create('viajes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $tabla->foreignUuid('vehiculo_id')->constrained('vehiculos')->cascadeOnDelete();

            // El conductor queda registrado aunque no haya asignacion formal:
            // en la practica alguien condujo, y el viaje existe igual.
            $tabla->foreignUuid('conductor_id')
                ->nullable()
                ->constrained('conductores')
                ->nullOnDelete();

            $tabla->foreignUuid('asignacion_id')
                ->nullable()
                ->constrained('asignaciones')
                ->nullOnDelete();

            $tabla->string('origen', 160)->nullable();
            $tabla->string('destino', 160)->nullable();
            $tabla->string('proposito', 255)->nullable();

            // 'planificado' | 'en_curso' | 'finalizado' | 'cancelado'
            $tabla->string('estado', 24)->default('planificado');

            $tabla->timestampTz('fecha_salida')->nullable();
            $tabla->timestampTz('fecha_retorno')->nullable();

            // Kilometraje de salida y de retorno. El diferencial es el
            // recorrido del viaje, y alimenta el rendimiento si el viaje
            // tiene cargas de combustible asociadas.
            $tabla->unsignedInteger('km_salida')->nullable();
            $tabla->unsignedInteger('km_retorno')->nullable();

            $tabla->text('observaciones')->nullable();

            $tabla->foreignUuid('usuario_registro_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete();

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'vehiculo_id', 'fecha_salida']);
            $tabla->index(['tenant_id', 'conductor_id', 'fecha_salida']);

            DB::unprepared(<<<'SQL'
                ALTER TABLE public.viajes
                ADD CONSTRAINT viajes_retorno_posterior
                CHECK (fecha_retorno IS NULL OR fecha_salida IS NULL OR fecha_retorno >= fecha_salida);
            SQL);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viajes');
        Schema::dropIfExists('asignaciones');
    }
};