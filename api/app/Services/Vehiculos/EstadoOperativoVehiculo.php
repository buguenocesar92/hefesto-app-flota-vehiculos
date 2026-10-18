<?php

declare(strict_types=1);

namespace App\Services\Vehiculos;

use App\Enums\EstadoAlerta;
use App\Enums\EstadoOperativoVehiculo;
use App\Enums\EstadoTemporalDocumento;
use App\Models\AlertaDocumento;
use App\Models\Documento;
use App\Models\Mantencion;
use App\Models\Vehiculo;
use Carbon\Carbon;

/**
 * Estado de un vehículo en DOS EJES, nunca uno solo.
 *
 * EL REQUISITO
 * ------------
 * Un vehículo puede estar EN MANTENCIÓN y tener documentos VENCIDOS al mismo
 * tiempo. Si el sistema tuviera un solo estado, tendría que elegir uno de los
 * dos, y el otro desaparecería de la pantalla. El dueño de la flota señaló esto
 * explícitamente: el problema aparece cuando el sistema esconde media
 * verdad.
 *
 * Un ejemplo concreto que lo aclara: una camioneta está en el taller con la
 * revisión técnica vencida. Si la pantalla dice solo "En mantención", quien la
 * mira entiende que el vehículo está siendo arreglado y que no hay nada más
 * que revisar. Al día siguiente sale del taller con la revisión vencida y
 * sigue sin enterarse. El sistema tenía el dato y no lo mostró.
 *
 * POR QUÉ DOS ESTRUCTURAS Y NO UNA
 * -------------------------------
 * `estadoOperativo` responde "¿puedo usar este vehículo ahora?".
 * `estadoDocumental` responde "¿está todo en regla?".
 *
 * Son preguntas distintas con respuestas distintas, y las dos se devuelven
 * siempre. Además se agrega `alertasPendientes`, porque el aviso de
 * vencimiento es la señal más urgente y no tiene por qué ser un documento.
 */
class EstadoOperativoVehiculo
{
    /**
     * Estado completo del vehículo.
     */
    public function paraVehiculo(Vehiculo $vehiculo, ?Carbon $hoy = null): EstadoVehiculo
    {
        $hoy = $hoy ?? Carbon::now();

        $operativo = $vehiculo->estadoOperativo();
        $mantencion = $vehiculo->mantenciones()->where('estado', 'en_proceso')->first();

        $documentos = $vehiculo->documentos()
            ->where('estado', 'vigente')
            ->with('tipoDocumento')
            ->get();

        $diasAnticipacion = $this->mayorAnticipacionConfigurada($vehiculo->tenant_id);

        $documental = $this->estadoDocumentalDe($documentos, $diasAnticipacion, $hoy);

        $alertasPendientes = AlertaDocumento::query()
            ->where('vehiculo_id', $vehiculo->id)
            ->whereIn('estado', [EstadoAlerta::Pendiente->value, EstadoAlerta::Error->value])
            ->count();

        return new EstadoVehiculo(
            operativo: $operativo,
            documental: $documental,
            vehiculo: $vehiculo,
            mantencionEnCurso: $mantencion,
            documentos: $documentos,
            alertasPendientes: $alertasPendientes,
            hayAlgoCritico: $documental === EstadoTemporalDocumento::Vencido,
        );
    }

    /**
     * Estado documental: el PEOR de todos los documentos.
     *
     * Se usa el peor a propósito. Si un vehículo tiene el permiso vigente y
     * el seguro vencido, el estado documental es "vencido": el conductor no
     * puede circular, y presentar el mejor de los dos sería engañoso.
     *
     * El detalle de cada documento NO se pierde: viaja en `documentos`, y la
     * interfaz muestra documento por documento cuál es el problema.
     */
    private function estadoDocumentalDe(
        iterable $documentos,
        int $diasAnticipacion,
        Carbon $hoy,
    ): EstadoTemporalDocumento {
        $peor = EstadoTemporalDocumento::SinFecha;

        foreach ($documentos as $documento) {
            /** @var Documento $documento */
            $estado = $this->estadoDeDocumento($documento, $diasAnticipacion, $hoy);

            if ($this->esMasCritico($estado, $peor)) {
                $peor = $estado;
            }
        }

        return $peor;
    }

    private function estadoDeDocumento(
        Documento $documento,
        int $diasAnticipacion,
        Carbon $hoy,
    ): EstadoTemporalDocumento {
        $efectiva = $documento->fechaVencimientoEfectiva();

        if ($efectiva['fecha'] === null) {
            return EstadoTemporalDocumento::SinFecha;
        }

        $dias = (int) $hoy->copy()->startOfDay()
            ->diffInDays($efectiva['fecha']->copy()->startOfDay(), false);

        if ($dias < 0) {
            return EstadoTemporalDocumento::Vencido;
        }

        return $dias <= $diasAnticipacion
            ? EstadoTemporalDocumento::PorVencer
            : EstadoTemporalDocumento::Vigente;
    }

    /** Orden de criticidad, de menor a mayor. */
    private function esMasCritico(
        EstadoTemporalDocumento $candidato,
        EstadoTemporalDocumento $actual,
    ): bool {
        $orden = [
            EstadoTemporalDocumento::Retirado->value => 0,
            EstadoTemporalDocumento::SinFecha->value => 1,
            EstadoTemporalDocumento::Vigente->value => 2,
            EstadoTemporalDocumento::PorVencer->value => 3,
            EstadoTemporalDocumento::Vencido->value => 4,
        ];

        return ($orden[$candidato->value] ?? 0) > ($orden[$actual->value] ?? 0);
    }

    /**
     * Mayor anticipación configurada por la flota.
     *
     * Define cuándo un documento pasa de "vigente" a "por vencer" en la
     * pantalla. Sale de `configuraciones_alerta`, o sea, es una decisión del
     * cliente, no un número impuesto por Hefesto.
     */
    private function mayorAnticipacionConfigurada(?string $tenantId): int
    {
        $porDefecto = config('flota.umbrales_alerta_dias', [60, 30, 15, 7, 1]);
        $mayor = is_array($porDefecto) && $porDefecto !== []
            ? (int) max($porDefecto)
            : 30;

        if ($tenantId === null) {
            return $mayor;
        }

        $configurado = \App\Models\ConfiguracionAlerta::query()
            ->where('tenant_id', $tenantId)
            ->where('activa', true)
            ->max('umbral_dias');

        if ($configurado === null) {
            return $mayor;
        }

        return max($mayor, (int) $configurado);
    }
}