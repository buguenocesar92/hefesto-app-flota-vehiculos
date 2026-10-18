<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `documentos`: EL CENTRO DEL PRODUCTO.
 *
 * Un documento que vence en silencio es el problema que Hefesto reconoce: el
 * permiso de circulacion vence manana y el chofer no lo sabe; el seguro esta
 * vencido y nadie se entera hasta el accidente. Esta tabla existe para que eso
 * no pueda pasar.
 *
 * TRES DECISIONES DE MODELO QUE IMPORTAN
 * --------------------------------------
 *
 * 1. VENCIMIENTO POR FECHA Y VENCIMIENTO POR INSPECCION ESTAN SEPARADOS.
 *    Aqui solo vive el vencimiento POR FECHA (`fecha_vencimiento`), que es el
 *    que viene impreso en el documento y es el unico dato que no se estima.
 *    El vencimiento por inspeccion, que depende de una periodicidad
 *    configurada, vive en `reglas_frecuencia_documento`. La interfaz muestra
 *    los dos por separado y nunca los suma.
 *
 * 2. EL ESTADO TEMPORAL NO SE GUARDA, SE CALCULA.
 *    `estado` guarda solo lo administrativo (vigente, anulado, historico).
 *    Si "vencido" estuviera guardado, bastaria con que alguien actualizara la
 *    fecha del documento sin pasar por el codigo que escribe el estado, y el
 *    vehiculo apareceria vigente con el permiso caducado. "Vencido" y "por
 *    vencer" se derivan de `fecha_vencimiento` en cada lectura. Ver
 *    `App\Enums\EstadoTemporalDocumento`.
 *
 * 3. UN SOLO DOCUMENTO, DOS SUJETOS POSIBLES.
 * El permiso de circulacion es del vehiculo; la licencia de conducir es del
 * conductor. En lugar de dos tablas iguales con la mitad del codigo
 * duplicado, hay una sola con una restriccion que obliga a informar
 * exactamente uno de los dos.
 *
 * ADEMAS
 * -------
 * El indice unico parcial `un_documento_vigente_por_tipo` es una regla de
 * negocio apoyada en la base: no puede haber dos permisos de circulacion
 * vigentes para el mismo vehiculo. Si se cargara el segundo, la base lo
 * rechaza. No es una validacion que alguien pueda saltarse olvidadose de
 * reescribir el formulario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Exactamente uno de los dos. La restriccion de abajo lo exige.
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

            // Numero que figura en el documento (poliza, numero de permiso).
            $tabla->string('numero', 120)->nullable();

            $tabla->string('emisora', 160)->nullable();
            $tabla->string('organo_emisor', 160)->nullable();

            $tabla->date('fecha_emision')->nullable();

            // El vencimiento por fecha. Es el unico dato de vencimiento que
            // el sistema da por cierto, porque viene impreso en el documento.
            $tabla->date('fecha_vencimiento')->nullable();

            // 'vigente' | 'anulado' | 'historico'
            $tabla->string('estado', 20)->default('vigente');

            $tabla->text('observaciones')->nullable();

            $tabla->foreignUuid('usuario_registro_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete();

            $tabla->timestampsTz();
            $tabla->softDeletesTz();

            // Un documento no puede vencer antes de emitirse.
            DB::unprepared(<<<'SQL'
                ALTER TABLE public.documentos
                ADD CONSTRAINT documentos_vencimiento_posterior_a_emision
                CHECK (
                    fecha_vencimiento IS NULL
                    OR fecha_emision IS NULL
                    OR fecha_vencimiento >= fecha_emision
                );
            SQL);

            // Exactamente un sujeto: vehiculo o conductor, nunca los dos y
            // nunca ninguno.
            DB::unprepared(<<<'SQL'
                ALTER TABLE public.documentos
                ADD CONSTRAINT documentos_un_sujeto
                CHECK (
                    (vehiculo_id IS NOT NULL AND conductor_id IS NULL)
                    OR (vehiculo_id IS NULL AND conductor_id IS NOT NULL)
                );
            SQL);

            // Las consultas de vencimiento recorren los documentos vigentes
            // ordenados por fecha. Este indice es el que sostiene la tarea
            // programada de alertas.
            $tabla->index(
                ['tenant_id', 'estado', 'fecha_vencimiento'],
                'documentos_vencimiento_busqueda'
            );

            $tabla->index(['tenant_id', 'conductor_id', 'estado']);
            $tabla->index(['tenant_id', 'tipo_documento_id']);

            // No puede haber dos documentos vigentes del mismo tipo para el
            // mismo sujeto.
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX documentos_un_vigente_por_tipo
                ON public.documentos (tenant_id, tipo_documento_id, vehiculo_id, conductor_id)
                WHERE estado = 'vigente';
            SQL);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos');
    }
};