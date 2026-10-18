<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `alertas_documento`: el aviso de vencimiento ya generado.
 *
 * POR QUE EXISTE COMO TABLA Y NO COMO UN CORREO QUE SE MANDA
 * ---------------------------------------------------------
 * "Mandar el aviso" y "generar el aviso" son dos cosas distintas. Si el
 * generador escribiera directamente en el correo, no habria forma de saber
 * que ya se aviso, y la tarea_programada del dia siguiente mandaria lo
 * mismo otra vez. El operador recibiria el mismo correo siete veces y dejaria
 * de mirarlos, que es exactamente el problema que el sistema viene a
 * resolver.
 *
 * Aqui queda el aviso generado, con su estado. La tarea programada genera; el
 * worker de cola envia.
 *
 * LA GARANTIA DE "UNA SOLA VEZ POR DOCUMENTO Y PERIODO"
 * ---------------------------------------------------
 * La columna `periodo_clave` es una cadena DETERMINISTA que describe el
 * periodo del aviso:
 *
 *     v:2026-05-20:umbral:30      a 30 dias del vencimiento del 20-05
 *     v:2026-05-20:umbral:15      a 15 dias del mismo vencimiento
 *     v:2026-05-20:vencido        ya vencido
 *     v:2026-05-20:insistencia:7  vencido y aun nadie lo resolvio
 *
 * Con el indice unico de abajo, la base garantiza que para un documento dado
 * y un periodo dado hay COMO MAXIMO una alerta. El generador inserta con
 * `ON CONFLICT DO NOTHING`, de modo que reejecutar la tarea programada no
 * duplica nada: el conflicto simplemente no inserta y sigue.
 *
 * No es "el codigo se curaba de no repetir". Es que la base no lo
 * permite.
 *
 * Y el periodo incluye la fecha de vencimiento (`v:2026-05-20`), lo que
 * resuelve el caso de la renovacion: cuando el documento se renueva con una
 * fecha nueva, el periodo es distinto y los avisos del ciclo anterior no
 * bloquean los nuevos. Sin ese componente, renovar el permiso de circulacion
 * dejaria al vehiculo sin avisos para siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas_documento', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $tabla->foreignUuid('documento_id')->constrained('documentos')->cascadeOnDelete();

            // Copia minima del contexto, para que el listado de alertas no
            // dependa de una consulta extra por fila y para que el aviso
            // conserve el sentido aunque despues cambie el documento.
            $tabla->foreignUuid('vehiculo_id')
                ->nullable()
                ->constrained('vehiculos')
                ->cascadeOnDelete();

            $tabla->foreignUuid('conductor_id')
                ->nullable()
                ->constrained('conductores')
                ->cascadeOnDelete();

            $tabla->foreignUuid('tipo_documento_id')
                ->constrained('tipos_documento')
                ->restrictOnDelete();

            // Fecha usada para el calculo. Es parte de `periodo_clave` a
            // proposito: cambiar la fecha abre un periodo nuevo.
            $tabla->date('fecha_vencimiento_referencia');

            // 'documento' | 'regla_frecuencia'. Un aviso generado desde una
            // regla de frecuencia se muestra marcado como estimado, nunca
            // como vencimiento legal.
            $tabla->string('origen_vencimiento', 32)->default('documento');

            // 'umbral' | 'vencido' | 'insistencia'
            $tabla->string('tipo', 24);

            // Dias de anticipacion del aviso. NULL para 'vencido'.
            $tabla->unsignedInteger('umbral_dias')->nullable();

            // Foto del calculo en el momento de generar. Sirve para que un
            // aviso vieux siga siendo entendible, y para poder depurar
            // discrepancias sin recalcular a posteriori.
            $tabla->integer('dias_restantes');

            $tabla->string('periodo_clave', 80);

            // 'pendiente' | 'enviada' | 'resuelta' | 'error' | 'descartada'
            $tabla->string('estado', 24)->default('pendiente');

            $tabla->string('canal', 32)->nullable();
            $tabla->json('destinatarios')->nullable();
            $tabla->timestampTz('enviada_at')->nullable();
            $tabla->text('error_texto')->nullable();

            $tabla->timestampTz('resuelta_at')->nullable();

            // Identificador de la ejecucion de la tarea que la genero. Sirve
            // para responder "esta alerta salio de la corrida de anoche o de
            // la de hoy".
            $tabla->string('origen_generacion', 80)->nullable();

            $tabla->timestampsTz();

            /*
            |------------------------------------------------------------------
            | EL INDICE UNICO: el corazon del requisito
            |------------------------------------------------------------------
            */
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX alertas_documento_unica_por_periodo
                ON public.alertas_documento (documento_id, periodo_clave);
            SQL);

            // El tablero de vencimientos.
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'fecha_vencimiento_referencia']);
            $tabla->index(['tenant_id', 'vehiculo_id']);

            // Los dias restantes nunca se guardan sin la fecha con la que se
            // calcularon.
            DB::unprepared(<<<'SQL'
                ALTER TABLE public.alertas_documento
                ADD CONSTRAINT alertas_umbral_coherente
                CHECK (
                    (tipo = 'umbral' AND umbral_dias IS NOT NULL)
                    OR (tipo <> 'umbral' AND umbral_dias IS NULL)
                );
            SQL);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_documento');
    }
};