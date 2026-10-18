<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `reglas_frecuencia_documento`: el vencimiento POR INSPECCION,
 * separado del vencimiento POR FECHA.
 *
 * Hay documentos que no vencen el dia que dice el papel, sino cuando pasa
 * una inspeccion. Para esos, la fecha util la calcula el sistema a partir de
 * una periodicidad configurada y de la ultima inspeccion registrada.
 *
 * POR QUE ESTO NO ES LO MISMO QUE `documentos.fecha_vencimiento`
 * -----------------------------------------------------------
 * `documentos.fecha_vencimiento` es un hecho: esta impreso en el documento.
 * La periodicidad de aqui es una CONFIGURACION: alguien decidio que se
 * renueva cada cierta cantidad de dias o cada cierta cantidad de
 * kilometros.
 *
 * Si la periodicidad esta mal, o si la ultima inspeccion se registro tarde,
 * la fecha calculada queda mala. Por eso el resultado se marca siempre con
 * `origen = 'regla_frecuencia'` y la interfaz lo muestra como estimado, con
 * la fecha impresa del documento al lado cuando existe. Ver
 * `App\Models\Documento::fechaVencimientoEfectiva()`.
 *
 * PENDIENTE POR CONFIRMAR
 * -----------------------
 * `periodicidad_dias` arranca en NULL en la semilla. Hefesto NO carga
 * periodicidades de ejemplo tomadas de memoria: deben confirmarse con un
 * profesional del transporte o con el cliente, y quedar registro de quien lo
 * hizo en `confirmado_por` y `fuente_plazo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglas_frecuencia_documento', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Una regla por documento. Si el documento se renueva, se actualiza
            // esta fila: se actualiza el historico de inspecciones en la misma
            // tabla, porque el valor que importa es "cuando fue la ultima".
            $tabla->foreignUuid('documento_id')
                ->unique()
                ->constrained('documentos')
                ->cascadeOnDelete();

            $tabla->foreignUuid('tipo_documento_id')
                ->constrained('tipos_documento')
                ->restrictOnDelete();

            // Periodicidad en dias o en kilometros, o en las dos.
            $tabla->unsignedInteger('periodicidad_dias')->nullable();
            $tabla->unsignedInteger('periodicidad_km')->nullable();

            $tabla->date('ultima_inspeccion_fecha')->nullable();
            $tabla->unsignedInteger('ultima_inspeccion_km')->nullable();

            // Quien consta que confirmo la periodicidad y con que fuente.
            $tabla->string('confirmado_por', 160)->nullable();
            $tabla->string('fuente_plazo', 255)->nullable();
            $tabla->timestampTz('confirmado_at')->nullable();

            $tabla->boolean('activa')->default(true);
            $tabla->timestampsTz();

            // Una regla tiene que medir en algo. Una periodicidad en dias
            // nula y en kilometros nula es una regla que no dice nada.
            DB::unprepared(<<<'SQL'
                ALTER TABLE public.reglas_frecuencia_documento
                ADD CONSTRAINT reglas_frecuencia_mede_en_algo
                CHECK (periodicidad_dias IS NOT NULL OR periodicidad_km IS NOT NULL);
            SQL);

            $tabla->index(['tenant_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglas_frecuencia_documento');
    }
};