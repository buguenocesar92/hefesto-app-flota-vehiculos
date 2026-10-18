<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `vehiculos`: la ficha del vehiculo.
 *
 * NOTA DE DISENO IMPORTANTE: NO hay columna `kilometraje_actual`.
 *
 * El kilometraje no se escribe a mano: se deriva de la ultima lectura
 * registrada en `lecturas_kilometraje` (migracion 2026_03_01_000012), y cada
 * lectura declara de donde salio (odometro, mantencion, carga de combustible o
 * ajuste justificado). Si se guardara aqui un numero, habria dos fuentes de
 * verdad y la diferencia entre ambas seria invisible. Ver
 * `App\Services\Kilometraje\RegistroDeKilometraje`.
 *
 * Tampoco hay columna `estado_documental` ni `estado_operativo`: ambas se
 * calculan al consultar, porque un estado guardado se queda viejo el dia
 * que el documento vence y nadie lo actualiza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehiculos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Numero interno de la flotilla. Un operador puede tener mas de
            // una flota y el mismo vehiculo tiene dos placas distintas.
            $tabla->string('numero_interno', 32)->nullable();

            // Patente chilena. Se guarda NORMALIZADA (ver
            // `App\Support\NormalizadorPatente`: sin espacios, sin puntos,
            // con guion antes de los digitos, en mayusculas). Sin esa
            // normalizacion existirian "ABCD12", "AB CD 12" y "ab·cd-12" como
            // tres vehiculos distintos.
            $tabla->string('patente', 16);

            // VIN: 17 caracteres, sin las letras I, O y Q, que la norma
            // internacional excluye para evitar confusiones.
            $tabla->string('vin', 17)->nullable();

            $tabla->string('marca', 80);
            $tabla->string('modelo', 80);
            $tabla->unsignedSmallInteger('anio');

            $tabla->string('color', 40)->nullable();
            $tabla->string('chasis', 40)->nullable();

            $tabla->foreignUuid('tipo_servicio_id')
                ->constrained('tipos_servicio')
                ->restrictOnDelete();

            $tabla->foreignUuid('centro_costo_id')
                ->nullable()
                ->constrained('centros_costo')
                ->nullOnDelete();

            $tabla->unsignedInteger('kilometraje_referencia')->nullable();

            $tabla->foreignUuid('usuario_registro_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete();

            // `activo` es administrative (el vehiculo existe en la flota).
            // Que este en el taller o con documentos vencidos es otra cosa,
            // y se calcula. Ver `App\Services\Vehiculos\EstadoOperativo`.
            $tabla->boolean('activo')->default(true);

            $tabla->date('retirado_at')->nullable();
            $tabla->text('observaciones')->nullable();

            $tabla->timestampsTz();
            $tabla->softDeletesTz();

            // Una patente no se repite dentro de la misma flota.
            $tabla->unique(['tenant_id', 'patente']);
            $tabla->unique(['tenant_id', 'vin']);
            $tabla->index(['tenant_id', 'activo']);
            $tabla->index(['tenant_id', 'centro_costo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehiculos');
    }
};