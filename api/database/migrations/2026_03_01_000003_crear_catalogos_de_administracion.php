<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogos de administracion: `tipos_servicio`, `centros_costo` y
 * `talleres`.
 *
 * Los tres van juntos porque los tres son datos de referencia que existen
 * antes que el vehiculo y que el vehiculo referencia:
 *
 *   - tipo de servicio: define como se opera el vehiculo y si exige
 *     habilitacion especial (por ejemplo, transporte de pasajeros).
 *   - centro de costo: es donde se imputa el gasto. Sin esto no hay
 *     consolidated por unidad de negocio.
 *   - taller: es quien ejecuta la mantencion.
 *
 * Todos llevan `tenant_id` y por lo tanto RLS (migracion
 * 2026_03_01_000014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_servicio', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $tabla->string('codigo', 40);
            $tabla->string('nombre', 120);
            $tabla->text('descripcion')->nullable();

            // Marca los tipos de servicio que exigen una habilitacion
            // adicional (por ejemplo, transporte de pasajeros o de
            // sustancias peligrosas). Es una Bandera de trabajo para ordenar
            // la documentacion que se pide por vehiculo: el sistema NO
            // deduce por si mismo que documentos exige la normativa.
            $tabla->boolean('requiere_habilitacion')->default(false);

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
        });

        Schema::create('centros_costo', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Permite agrupar: "Regiones" -> "Region Metropolitana" ->
            // "Zona Norte".
            $tabla->foreignUuid('centro_costo_padre_id')
                ->nullable()
                ->constrained('centros_costo')
                ->nullOnDelete();

            $tabla->string('codigo', 40);
            $tabla->string('nombre', 160);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
        });

        Schema::create('talleres', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $tabla->string('nombre', 160);
            $tabla->string('rut', 20)->nullable();
            $tabla->string('contacto', 160)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->string('especialidad', 160)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talleres');
        Schema::dropIfExists('centros_costo');
        Schema::dropIfExists('tipos_servicio');
    }
};