<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Vehiculo;
use App\Models\Usuario;

/**
 * Autorización sobre vehículos.
 *
 * Dos capas, y conviene no confundirlas:
 *
 * 1. La RLS garantiza que la consulta solo devuelva filas de SU flota. Eso no
 *    lo hace esta Policy: lo hace PostgreSQL.
 *
 * 2. Esta Policy decide qué puede hacer con SU propio vehículo. Un rol de
 *    consulta no puede escribir, ni siquiera sobre su propia flota.
 *
 * Y una regla de forma: cuando la Policy rechaza, la respuesta debería ser
 * 404, no 403, porque un 403 confirmaría que el recurso existe. Para el
 * aislamiento entre flotas el caso habitual es justamente que el recurso no
 * existe a ojos de quien pregunta.
 */
class VehiculoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Vehiculo $vehiculo): bool
    {
        return $vehiculo->tenant_id === $usuario->tenant_id;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->puedeOperar();
    }

    public function update(Usuario $usuario, Vehiculo $vehiculo): bool
    {
        return $vehiculo->tenant_id === $usuario->tenant_id
            && $usuario->puedeOperar();
    }

    /**
     * Retirar un vehículo es más fuerte que editarlo: cambia su estado en la
     * flota y aparece en los reportes. Es una decisión de administración.
     */
    public function delete(Usuario $usuario, Vehiculo $vehiculo): bool
    {
        return $vehiculo->tenant_id === $usuario->tenant_id
            && $usuario->rol?->puedeConfigurar() === true;
    }
}