<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `tipos_documento`: el catalogo de documentos que maneja la flota.
 *
 * ES GLOBAL Y NO LLEVA `tenant_id` NI RLS. Es una decision consciente:
 *
 * 1. Los tipos de documento (permiso de circulacion, seguro obligatorio,
 *    revision tecnica, padron de fiscalizacion, licencia de conducir) no son
 *    datos de una flota: son el catalogo nacional. Duplicarlos por cliente no
 *    agrega informacion, solo lugares donde desincronizarse.
 * 2. La tabla no contiene NADA sensible: ni nombres de empresas, ni RUT, ni
 *    numeros de documento, ni correos. Solo el nombre del tipo y la
 *    configuracion. Que un atacante la lea no revela nada de ninguna flota.
 * 3. Lo que SI es de cada flota vive en `configuraciones_alerta`: cuantos
 *    dias antes querer que le avisen, a quien, y por que canal.
 *
 * SOBRE LOS PLAZOS LEGALES
 * ------------------------
 * Las columnas `plazo_legal_*` existen y van deliberadamente vacias en la
 * semilla. El sistema NO calcula la fecha legal de vencimiento de un
 * documento a partir de una periodicidad inventada: la fecha real se carga
 * tal como viene impresa en el documento.
 *
 * Si una flota configura una regla de frecuencia (por ejemplo "la revision
 * tecnica se renueva cada 12 meses"), el sistema usa esa regla para calcular
 * una fecha PROPUESTA, y la muestra marcada como estimada, nunca como
 * vencimiento legal.
 *
 * Hefesto debe confirmar los plazos legales aplicables con un profesional del
 * transporte o con el cliente antes de cargar el catalogo como configurado.
 * Escribir un plazo legal inventado en un sistema de gestion es un riesgo
 * serio, y por eso los campos quedan con el rastro de quien los confirmo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_documento', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();

            // Clave estable en codigo, usada por la semilla y por las
            // importaciones. No se renombra nunca: cambiar la clave rompe los
            // vinculos de los datos ya cargados.
            $tabla->string('clave', 60)->unique();

            $tabla->string('nombre', 160);
            $tabla->text('descripcion')->nullable();

            // 'vehiculo' | 'conductor'
            $tabla->string('sujeto', 20)->default('vehiculo');

            // Cuando es true, el generador de alertas exige fecha de
            // vencimiento. Por ejemplo, un permiso de circulacion sin fecha
            // es un dato incompleto que hay que reportar.
            $tabla->boolean('requiere_vencimiento')->default(true);

            // Donde se emite. Sirve para que el operador sepa a que oficina
            // tiene que ir. No es un campo que el sistema use para decidir
            // nada.
            $tabla->string('organo_emisor', 160)->nullable();

            /*
            |------------------------------------------------------------------
            | Plazos legales: pendientes de confirmacion
            |------------------------------------------------------------------
            |
            | Se dejan en NULL a proposito. Nada aqui esta verificado contra
            | la normativa chilena en el entorno donde se escribio el
            | sistema, y un plazo legal equivocado es peor que un plazo
            | ausente: el operador confiaria en el numero.
            |
            | Cuando Hefesto los confirme con un profesional del transporte o
            | con el cliente, se llenar `plazo_legal_dias`,
            | `plazo_legal_fuente` (norma o articulo), `plazo_legal_confirmado_por`
            | y `plazo_legal_confirmado_at`.
            */
            $tabla->unsignedInteger('plazo_legal_dias')->nullable();
            $tabla->string('plazo_legal_fuente', 255)->nullable();
            $tabla->string('plazo_legal_confirmado_por', 160)->nullable();
            $tabla->timestampTz('plazo_legal_confirmado_at')->nullable();

            $tabla->text('nota')->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();
        });

        /*
        |----------------------------------------------------------------------
        | Configuracion de alertas por flota
        |----------------------------------------------------------------------
        |
        | Aqui vive lo que realmente es configurable por el cliente:
        | cuantos dias antes del vencimiento quiere que le avisen.
        |
        | `tipo_documento_id` en NULL significa "este umbral aplica a todos los
        | tipos de documento de la flota". Por eso el indice unico esta
        | partido: en PostgreSQL los NULL son distintos entre si, asi que
        | `UNIQUE (tenant_id, tipo_documento_id, umbral_dias)` no evitaria dos
        | filas globales con el mismo umbral. El indice parcial si.
        */
        Schema::create('configuraciones_alerta', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $tabla->foreignUuid('tipo_documento_id')
                ->nullable()
                ->constrained('tipos_documento')
                ->cascadeOnDelete();

            // Dias de anticipacion del aviso. Es un plazo de AVISO INTERNO,
            // no un plazo legal: 30 significa "avisame 30 dias antes".
            $tabla->unsignedInteger('umbral_dias');

            // Cuantos dias despues del vencimiento se genera el aviso
            // "documento vencido". 0 significa el mismo dia.
            $tabla->unsignedInteger('dias_insistencia_vencido')->default(0);

            // 'correo' | 'correo_interno' | 'ninguno'
            $tabla->string('canal', 32)->default('correo');

            // Lista opcional de correos. Vacia significa "a todos los
            // administradores de la flota".
            $tabla->json('destinatarios')->nullable();

            $tabla->boolean('activa')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'tipo_documento_id', 'umbral_dias']);
            $tabla->index(['tenant_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_alerta');
        Schema::dropIfExists('tipos_documento');
    }
};