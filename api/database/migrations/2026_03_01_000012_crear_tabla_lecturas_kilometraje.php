<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `lecturas_kilometraje`: DE DONDE SALE EL KILOMETRAJE.
 *
 * Este es el detalle de dominio que el dueño de la flota señaló desde el
 * principio: el kilometraje no se escribe. Se LEE de un lado o de otro, y
 * cada lectura deja constancia de cuál fue ese lado.
 *
 * Fuentes (`fuente`):
 *   odometro            : el chofer o el operador leyo el tablero.
 *   mantencion          : el taller informo el kilometraje al entregar.
 *   carga_combustible   : se leyo el odometro al cargar combustible.
 *   ajuste              : correccion justificada (odometro cambiado, error de
 *                         tipeo). Exige `motivo_ajuste` y queda con autor.
 *
 * DOS REGLAS QUE LA BASE IMPONE
 * ----------------------------
 *
 * 1. No se puede registrar un kilometraje menor que el ultimo sin declararlo
 *    como ajuste. Sin esta restriccion, un 40.000 tipeado donde iba 140.000
 *    rompe de forma silenciosa todo el calculo de mantenciones por
 *    kilometraje y todo el rendimiento de combustible. Con la restriccion, el
 *    error se vuelve visible y exige una justificacion.
 *
 * 2. Un ajuste sin motivo no se puede insertar. Un "ajuste" sin explicacion
 *    es exactamente el campo libre que este diseno elimina.
 *
 * Cada lectura ademas apunta a su origen concreto (mantencion, viaje o carga
 * de combustible) cuando existe. Eso permite responder "este kilometraje de
 * donde salio" sin adivinar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecturas_kilometraje', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $tabla->foreignUuid('vehiculo_id')->constrained('vehiculos')->cascadeOnDelete();

            $tabla->unsignedInteger('km');

            // 'odometro' | 'mantencion' | 'carga_combustible' | 'ajuste'
            $tabla->string('fuente', 32);

            $tabla->date('fecha');

            // Encadenamiento: cada lectura sabe cual fue la anterior. Con eso
            // el diferencial entre dos lecturas consecutivas es el tramo
            // recorrido, que es lo que alimenta el rendimiento.
            $tabla->foreignUuid('lectura_anterior_id')
                ->nullable()
                ->constrained('lecturas_kilometraje')
                ->nullOnDelete();

            // Origen concreto, cuando la lectura viene de un registro real.
            $tabla->foreignUuid('mantencion_id')
                ->nullable()
                ->constrained('mantenciones')
                ->nullOnDelete();

            $tabla->foreignUuid('viaje_id')
                ->nullable()
                ->constrained('viajes')
                ->nullOnDelete();

            $tabla->foreignUuid('carga_combustible_id')
                ->nullable()
                ->constrained('cargas_combustible')
                ->nullOnDelete();

            $tabla->foreignUuid('conductor_id')
                ->nullable()
                ->constrained('conductores')
                ->nullOnDelete();

            // Quien registro la lectura. Sin autor, un numero sin origen es
            // informacion que nadie puede defender despues.
            $tabla->foreignUuid('usuario_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete();

            // Obligatorio solo cuando `fuente = 'ajuste'`.
            $tabla->text('motivo_ajuste')->nullable();

            $tabla->text('observaciones')->nullable();

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'vehiculo_id', 'km'], 'lecturas_orden');
            $tabla->index(['tenant_id', 'vehiculo_id', 'fecha']);

            DB::unprepared(<<<'SQL'
                ALTER TABLE public.lecturas_kilometraje
                ADD CONSTRAINT lecturas_kilometraje_positivo
                CHECK (km > 0);
            SQL);

            // Un ajuste tiene que decir por que. Un ajuste sin motivo es el
            // campo libre que este modelo existe para eliminar.
            DB::unprepared(<<<'SQL'
                ALTER TABLE public.lecturas_kilometraje
                ADD CONSTRAINT lecturas_ajuste_exige_motivo
                CHECK (
                    (fuente = 'ajuste' AND motivo_ajuste IS NOT NULL AND btrim(motivo_ajuste) <> '')
                    OR (fuente <> 'ajuste')
                );
            SQL);

            DB::unprepared(<<<'SQL'
                ALTER TABLE public.lecturas_kilometraje
                ADD CONSTRAINT lecturas_ajuste_exige_autor
                CHECK (fuente <> 'ajuste' OR usuario_id IS NOT NULL);
            SQL);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecturas_kilometraje');
    }
};