<?php

declare(strict_types=1);

namespace App\Services\Kilometraje;

use App\Enums\FuenteKilometraje;
use App\Models\LecturaKilometraje;
use App\Models\Mantencion;
use App\Models\Vehiculo;
use Illuminate\Support\Facades\DB;

/**
 * Registro de kilometraje CON SU FUENTE.
 *
 * El detalle de dominio que motiva este servicio: el kilometraje no se
 * escribe, se lee de un lado o de otro, y el sistema necesita saber de cuál.
 *
 * Aquí hay tres reglas que se aplican SIEMPRE, incluso si alguien llama al
 * servicio desde otro lado del código:
 *
 * 1. La lectura se encadena con la anterior. Cada fila sabe cuál fue la
 *    previa, y con eso el tramo recorrido se calcula sin ambigüedad.
 *
 * 2. Un kilometraje menor que el último NO se acepta como lectura normal. Hay
 *    que declararlo `ajuste`, con motivo y con autor. Sin esto, un 40.000
 *    donde iba 140.000 rompe en silencio el plan de mantenciones por
 *    kilometraje y el cálculo de rendimiento.
 *
 * 3. Cuando la lectura viene de una mantención, el taller pasó el odómetro al
 *    entregar: es tan válido como leerlo en la calle, y cuenta igual.
 */
class RegistroDeKilometraje
{
    /**
     * Registra una lectura para un vehículo.
     *
     * @throws KilometrajeIncoherente si el kilometraje retrocede sin ajuste.
     */
    public function registrar(
        Vehiculo $vehiculo,
        int $km,
        FuenteKilometraje $fuente,
        string $fecha,
        ?string $usuarioId = null,
        ?string $conductorId = null,
        ?Mantencion $mantencion = null,
        ?string $viajeId = null,
        ?string $cargaCombustibleId = null,
        ?string $motivoAjuste = null,
        ?string $observaciones = null,
    ): LecturaKilometraje {
        $ultima = $vehiculo->lecturas()->orderByDesc('km')->first();

        if ($ultima !== null && $km < (int) $ultima->km && ! $fuente->exigeMotivo()) {
            throw new KilometrajeIncoherente(
                sprintf(
                    'El kilometraje indicado (%d) es menor que la última lectura (%d) del vehículo %s. '
                    .'Si la lectura es correcta, regístrela como ajuste con su motivo.',
                    $km,
                    (int) $ultima->km,
                    $vehiculo->patente
                )
            );
        }

        if ($fuente->exigeMotivo() && ($motivoAjuste === null || trim($motivoAjuste) === '')) {
            throw new KilometrajeIncoherente(
                'Un ajuste de kilometraje exige un motivo escrito.'
            );
        }

        if ($fuente->exigeMotivo() && $usuarioId === null) {
            throw new KilometrajeIncoherente(
                'Un ajuste de kilometraje exige un usuario responsable del cambio.'
            );
        }

        return DB::transaction(function () use (
            $vehiculo,
            $km,
            $fuente,
            $fecha,
            $usuarioId,
            $conductorId,
            $mantencion,
            $viajeId,
            $cargaCombustibleId,
            $motivoAjuste,
            $observaciones
        ): LecturaKilometraje {
            $lectura = LecturaKilometraje::create([
                'vehiculo_id' => $vehiculo->id,
                'km' => $km,
                'fuente' => $fuente->value,
                'fecha' => $fecha,
                'lectura_anterior_id' => $ultima?->id,
                'mantencion_id' => $mantencion?->id,
                'viaje_id' => $viajeId,
                'carga_combustible_id' => $cargaCombustibleId,
                'conductor_id' => $conductorId,
                'usuario_id' => $usuarioId,
                'motivo_ajuste' => $motivoAjuste,
                'observaciones' => $observaciones,
            ]);

            // El odómetro que entregó el taller es una fuente de primer nivel:
            // si nadie más registró nada, esta lectura pasa a ser el
            // kilometraje de referencia del vehículo.
            if ($vehiculo->kilometraje_referencia === null) {
                $vehiculo->forceFill(['kilometraje_referencia' => $km])->save();
            }

            return $lectura;
        });
    }

    /**
     * Registra el odómetro que el taller informó al entregar el vehículo.
     */
    public function registrarDesdeMantencion(
        Vehiculo $vehiculo,
        Mantencion $mantencion,
        int $km,
        ?string $usuarioId = null,
    ): LecturaKilometraje {
        return $this->registrar(
            vehiculo: $vehiculo,
            km: $km,
            fuente: FuenteKilometraje::Mantencion,
            fecha: $mantencion->fecha_ejecucion?->format('Y-m-d') ?? now()->format('Y-m-d'),
            usuarioId: $usuarioId,
            mantencion: $mantencion,
        );
    }

    /** Kilometraje vigente del vehículo: última lectura, o referencia. */
    public function kilometrajeActual(Vehiculo $vehiculo): ?int
    {
        return $vehiculo->kilometrajeActual();
    }
}