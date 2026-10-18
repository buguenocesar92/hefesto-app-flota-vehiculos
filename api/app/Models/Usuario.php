<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolUsuario;
use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo `Usuario`.
 *
 * La identidad real la resuelve Authentik. Aqui solo se guarda el
 * identificador del proveedor (`authentik_sub`) y lo que el sistema necesita
 * para autorizar: nombre, correo y rol dentro de la flota.
 */
class Usuario extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'usuarios';

    protected $fillable = [
        'authentik_sub',
        'nombre',
        'correo',
        'rol',
        'activo',
    ];

    protected $hidden = [
        'remember_token',
        'authentik_sub',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'rol' => RolUsuario::class,
            'ultimo_acceso_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /** El administrador ve y escribe todo dentro de su flota. */
    public function esAdministrador(): bool
    {
        return $this->rol === RolUsuario::Administrador;
    }

    /** El operador opera la flota, pero no cambia la configuracion. */
    public function puedeOperar(): bool
    {
        return in_array($this->rol, [RolUsuario::Administrador, RolUsuario::Operador], true);
    }
}