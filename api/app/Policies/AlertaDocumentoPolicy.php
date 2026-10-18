<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EstadoAlerta;
use App\Models\AlertaDocumento;
use App\Models\Usuario;

/**
 * Autorización sobre las alertas de vencimiento.
 *
 * Todos los roles leen la bandeja de alertas: un operador que no supiera qué
 * documentos están por vencer no podría hacer su trabajo.
 *
 * Marcar una alerta como resuelta es una operación de administrador, porque
 * significa "esto ya no es un problema" y eso es una afirmación sobre el
 * estado real del documento. Que cualquiera pudiera hacerlo convertiría la
 * bandeja en un buzón que se puede vaciar sin hacer nada.
 */
class AlertaDocumentoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, AlertaDocumento $alerta): bool
    {
        return $alerta->tenant_id === $usuario->tenant_id;
    }

    /**
     * Reintentar el envío de una alerta que quedó en error es una operación
     * técnica. Se permite a quien opera, porque el objetivo es que el aviso
     * llegue, no cambiar el estado de un documento.
     */
    public function reintentar(Usuario $usuario, AlertaDocumento $alerta): bool
    {
        return $alerta->tenant_id === $usuario->tenant_id
            && $usuario->puedeOperar();
    }

    public function resolver(Usuario $usuario, AlertaDocumento $alerta): bool
    {
        return $alerta->tenant_id === $usuario->tenant_id
            && $usuario->rol?->puedeConfigurar() === true;
    }

    /** La alerta queda resuelta solo cuando el documento se renueva o se anula. */
    public function marcarResuelta(Usuario $usuario, AlertaDocumento $alerta): bool
    {
        if ($alerta->estado === EstadoAlerta::Descartada) {
            return false;
        }

        return $this->resolver($usuario, $alerta);
    }
}