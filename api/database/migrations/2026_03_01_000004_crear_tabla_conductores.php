<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `conductores`.
 *
 * Un conductor tambien tiene documentos que vencen (sobre todo la licencia de
 * conducir), y se modelan en la misma tabla `documentos` con
 * `conductor_id` informado y `vehiculo_id` nulo. Ver migracion
 * 2026_03_01_000007.
 *
 * La licencia NO se duplica aqui como columna `licencia_vencimiento`: si
 * estuviera en dos sitios, la alerta se generaria desde el que se olvidara
 * actualizar. Un solo lugar, y el generador de alertas lo recorre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conductores', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // RUN del conductor chileno, guardado como texto.
            $tabla->string('rut', 20)->nullable();

            $tabla->string('nombre', 160);
            $tabla->string('apellido_paterno', 80)->nullable();
            $tabla->string('apellido_materno', 80)->nullable();

            // Clase de la licencia (A1, A2, B, C, D, E, F). Se guarda como
            // texto y no como numero por el orden alfabetico real.
            $tabla->string('clase_licencia', 8)->nullable();

            $tabla->string('telefono', 32)->nullable();
            $tabla->string('correo')->nullable();

            $tabla->foreignUuid('usuario_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete();

            $tabla->date('fecha_ingreso')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'activo']);
            $tabla->index(['tenant_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conductores');
    }
};