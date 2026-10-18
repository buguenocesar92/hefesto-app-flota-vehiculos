<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado OPERATIVO de un vehiculo.
 *
 * Y aqui esta el otro detalle de dominio que el propio dueño reconocio: un
 * vehiculo
 * puede estar EN MANTENCION y tener documentos VENCIDOS al mismo tiempo. Son
 * dos ejes distintos y no se mezclan en un solo estado, porque un estado
 * unico obliga a elegir uno de los dos y esconde el otro.
 *
 * Aqui solo vive el eje operativo. El documental (vigente, por vencer,
 * vencido) se calcula aparte en `EstadoTemporalDocumento` y se presenta
 * junto, nunca encima. Ver `App\Services\Vehiculos\EstadoOperativoVehiculo`.
 */
enum EstadoOperativoVehiculo: string
{
    /** En la flota, sinestion abierta y sin mantencion en curso. */
    case Disponible = 'disponible';

    /** Asignado a un conductor. */
    case Asignado = 'asignado';

    /** Con una mantencion en proceso. */
    case EnMantencion = 'en_mantencion';

    /** El operador lo dejo fuera de servicio de forma deliberada. */
    case FueraDeServicio = 'fuera_de_servicio';

    /** Dado de baja de la flota. */
    case Retirado = 'retirado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Asignado => 'Asignado',
            self::EnMantencion => 'En mantención',
            self::FueraDeServicio => 'Fuera de servicio',
            self::Retirado => 'Retirado',
        };
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }
}