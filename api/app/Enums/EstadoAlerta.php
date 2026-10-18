<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una alerta de vencimiento.
 *
 * El ciclo es: `pendiente` -> `enviada` -> `resuelta`.
 *
 * Un estado `error` NO es un detalle menor: significa que el aviso se genero
 * pero no se pudo entregar. Si no quedara registrado, el operador creeria que
 * le avisaron cuando en realidad nadie recibio nada, que es el peor resultado
 * posible en un sistema cuyo unico trabajo es avisar.
 */
enum EstadoAlerta: string
{
    case Pendiente = 'pendiente';
    case Enviada = 'enviada';
    case Resuelta = 'resuelta';
    case Error = 'error';

    /** El documento se renuevo o se corrigio antes de que se enviara el aviso. */
    case Descartada = 'descartada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Enviada => 'Enviada',
            self::Resuelta => 'Resuelta',
            self::Error => 'Error de envío',
            self::Descartada => 'Descartada',
        };
    }

    /** La alerta ya no espera hacer nada: se archiva sola. */
    public function esTerminal(): bool
    {
        return in_array($this, [self::Resuelta, self::Descartada], true);
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }
}