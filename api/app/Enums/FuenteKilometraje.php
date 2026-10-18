<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * De donde sale el kilometraje de una lectura.
 *
 * El detalle de dominio que motiva todo esto: el kilometraje NO se escribe.
 * Se lee de un lado o de otro, y el sistema necesita saber de cual para que
 * un numero sin origen no termine pareciendo un dato tan bueno como otro.
 */
enum FuenteKilometraje: string
{
    /** Lectura directa del tablero por parte del operador o el chofer. */
    case Odometro = 'odometro';

    /** Kilometraje informado por el taller al entregar el vehiculo. */
    case Mantencion = 'mantencion';

    /** Odometro leido en el momento de cargar combustible. */
    case CargaCombustible = 'carga_combustible';

    /**
     * Correccion justificada: odometro reemplazado, error de tipeo, Cambio
     * de vehiculo en la misma patente. Exige `motivo_ajuste` y autor, y lo
     * exige la base de datos, no solo el formulario.
     */
    case Ajuste = 'ajuste';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Odometro => 'Odómetro',
            self::Mantencion => 'Mantención',
            self::CargaCombustible => 'Carga de combustible',
            self::Ajuste => 'Ajuste justificado',
        };
    }

    /** Un ajuste es el unico caso que exige justificacion. */
    public function exigeMotivo(): bool
    {
        return $this === self::Ajuste;
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }
}