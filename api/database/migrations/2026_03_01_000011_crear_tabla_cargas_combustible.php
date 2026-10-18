<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `cargas_combustible`.
 *
 * El rendimiento NO se guarda. Se calcula.
 *
 * Es la diferencia entre "el sistema sabe" y "alguien escribio un numero".
 * Si `rendimiento_km_por_litro` fuera una columna, dependeria de que alguien
 * lo calculara bien y lo escribiera. Si es un calculo sobre las lecturas
 * consecutivas, es imposible que este desactualizado: se recalcula cada vez
 * que se consulta, y si las lecturas cambian, el rendimiento cambia con
 * ellas.
 *
 * `km_lectura` es el odometro leido al momento de cargar. Con la carga
 * anterior del mismo vehiculo, da el tramo recorrido y con eso el
 * rendimiento. Ver `App\Services\Combustible\CalculadoraDeRendimiento`.
 *
 * La carga tambien genera su propia lectura de kilometraje con
 * `fuente = 'carga_combustible'`: cargar combustible es una de las tres
 * maneras legitimas de saber cuanto lleva el vehiculo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargas_combustible', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $tabla->foreignUuid('vehiculo_id')->constrained('vehiculos')->cascadeOnDelete();

            $tabla->foreignUuid('conductor_id')
                ->nullable()
                ->constrained('conductores')
                ->nullOnDelete();

            $tabla->timestampTz('fecha');

            $tabla->decimal('litros', 10, 2);
            $tabla->decimal('precio_unitario', 12, 2);
            $tabla->decimal('total', 14, 0)->nullable();

            // 'gasolina' | 'diesel' | 'otro'
            $tabla->string('tipo_combustible', 40)->default('gasolina');

            $tabla->string('estacion', 160)->nullable();

            // Odometro al momento de cargar. Es la lectura que despues
            // alimenta el calculo de rendimiento.
            $tabla->unsignedInteger('km_lectura');

            // Identificacion opcional del cobro, util cuando el conductor
            // paga y hay que respaldarlo.
            $tabla->string('numero_boleta', 60)->nullable();

            $tabla->text('observaciones')->nullable();

            $tabla->foreignUuid('usuario_registro_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete();

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'vehiculo_id', 'fecha'], 'cargas_orden');
            $tabla->index(['tenant_id', 'fecha']);

            DB::unprepared(<<<'SQL'
                ALTER TABLE public.cargas_combustible
                ADD CONSTRAINT cargas_litros_positivos
                CHECK (litros > 0);
            SQL);

            DB::unprepared(<<<'SQL'
                ALTER TABLE public.cargas_combustible
                ADD CONSTRAINT cargas_precio_no_negativo
                CHECK (precio_unitario >= 0);
            SQL);

            DB::unprepared(<<<'SQL'
                ALTER TABLE public.cargas_combustible
                ADD CONSTRAINT cargas_km_lectura_positivo
                CHECK (km_lectura > 0);
            SQL);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargas_combustible');
    }
};