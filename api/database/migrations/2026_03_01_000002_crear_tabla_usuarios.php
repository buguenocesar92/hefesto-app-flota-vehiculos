<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `usuarios`: las personas que entran al sistema.
 *
 * La identidad NO vive aqui. La resuelve Authentik (OpenID Connect) y de
 * este lado solo se guarda el identificador del proveedor (`authentik_sub`),
 * que es el unico dato estable entre reinicios de Authentik. Guardar el
 * correo como identificador de login seria un error: un usuario puede
 * cambiar de correo y perderia el acceso a sus permisos.
 *
 * Roles (ver docs/03-MULTITENANCY-Y-ROLES.md):
 *   administrador : ve y escribe todo dentro de su flota.
 *   operador      : opera la flota (carga kilometraje, crea documentos,
 *                   agenda mantenciones). No cambia la configuracion.
 *   consulta      : solo lee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Identificador del sujeto en Authentik ("sub" del id_token).
            $tabla->string('authentik_sub', 255)->unique();

            /*
            | Hash SHA-256 del token de sesión que entrega Authentik al iniciar
            | sesión. Se guarda el hash, nunca el token: una filtración de la
            | base no entrega sesiones válidas.
            |
            | Es una columna aparte del `authentik_sub` a propósito. El "sub"
            | del proveedor es estable entre reinicios de Authentik y es lo
            | que identifica a la persona; el token cambia en cada inicio de
            | sesión. Confundir ambos hace que un cambio de correo borre el
            | acceso de la persona, o que renovar sesión sea imposible.
            */
            $tabla->string('token_hash', 64)->nullable()->unique();

            $tabla->string('nombre', 160);
            $tabla->string('correo')->unique();

            $tabla->string('rol', 32)->default('consulta');

            $tabla->boolean('activo')->default(true);

            // Un usuario desactivado conserva la fila: es la forma de que
            // quede el rastro de quien existio en la flota. El borrado duro
            // se reserva para el proceso de baja de la flota completa.
            $tabla->timestamp('ultimo_acceso_at')->nullable();

            $tabla->rememberToken();
            $tabla->timestampsTz();

            // El indice de tenant cubre el filtro que hace RLS en cada
            // consulta: (tenant_id, rol).
            $tabla->index(['tenant_id', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};