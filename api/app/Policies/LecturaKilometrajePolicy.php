<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LecturaKilometraje;
use App\Models\Usuario;

/**
 * Autorización sobre lecturas de kilometraje.
 *
 * Se puede agregar una lectura con rol de operador, pero no eliminarla ni
 * modificarla.
 *
 * El motivo es que una lectura es un REGISTRO DE UN HECHO, no un dato
 * editable. Si el odómetro se lee mal, se corrige con un ajuste posterior que
 * queda anotado, no borrando el número anterior: la diferencia entre las dos
 * versiones es justamente lo que permite entender qué pasó con ese vehículo.
 *
 * Un kilometraje que se puede borrar es un kilometraje en el que nadie puede
 * confiar, y entonces todo el plan de mantenciones por distancia deja de
 * servir.
 */
class LecturaKilometrajePolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, LecturaKilometraje $lectura): bool
    {
        return $lectura->tenant_id === $usuario->tenant_id;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->puedeOperar();
    }

    /**
     * No existe `update` ni `delete`: una lectura es inmutable.
     */
    public function update(Usuario $usuario, LecturaKilometraje $lectura): bool
    {
        return false;
    }

    public function delete(Usuario $usuario, LecturaKilometraje $lectura): bool
    {
        return false;
    }
}