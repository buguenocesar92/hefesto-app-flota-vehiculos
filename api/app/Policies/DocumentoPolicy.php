<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Documento;
use App\Models\Usuario;

/**
 * Autorización sobre documentos.
 *
 * El rol de consulta PUEDE ver documentos (su raison d'être), pero no puede
 * crearlos ni editarlos. Registrar un documento es una afirmación sobre lo que
 * el vehículo tiene: si cualquiera lo puede hacer, la información pierde valor
 * para todos los demás.
 */
class DocumentoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Documento $documento): bool
    {
        return $documento->tenant_id === $usuario->tenant_id;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->puedeOperar();
    }

    /**
     * Editar un documento incluye RENOVARLO, que es la operación que cambia
     * la fecha y reabre el ciclo de avisos. Un rol de lectura no renueva
     * documentos: si lo pudiera, podría "silenciar" una alerta simplemente
     * cargando una fecha nueva.
     */
    public function update(Usuario $usuario, Documento $documento): bool
    {
        return $documento->tenant_id === $usuario->tenant_id
            && $usuario->puedeOperar();
    }

    /** Anular un documento es una tarea de administración. */
    public function delete(Usuario $usuario, Documento $documento): bool
    {
        return $documento->tenant_id === $usuario->tenant_id
            && $usuario->rol?->puedeConfigurar() === true;
    }
}