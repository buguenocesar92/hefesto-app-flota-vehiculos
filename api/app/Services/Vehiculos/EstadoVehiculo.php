<?php

declare(strict_types=1);

namespace App\Services\Vehiculos;

use App\Enums\EstadoOperativoVehiculo;
use App\Enums\EstadoTemporalDocumento;
use App\Models\Documento;
use App\Models\Mantencion;
use App\Models\Vehiculo;

/**
 * Estado completo de un vehículo, en dos ejes.
 *
 * Estructura deliberadamente sin un campo `estado` único: el motivo está
 * explicado en `App\Services\Vehiculos\EstadoOperativoVehiculo`.
 */
final class EstadoVehiculo
{
    /**
     * @param  list<Documento>  $documentos
     */
    public function __construct(
        public readonly EstadoOperativoVehiculo $operativo,
        public readonly EstadoTemporalDocumento $documental,
        public readonly Vehiculo $vehiculo,
        public readonly ?Mantencion $mantencionEnCurso,
        public readonly array $documentos,
        public readonly int $alertasPendientes,
        public readonly bool $hayAlgoCritico,
    ) {}

    /**
     * El vehículo está en el taller y además tiene documentos vencidos.
     *
     * Esta combinación es el caso que motivó el diseño. El tablero la
     * muestra en una sección propia, porque ocultaría el hecho si solo
     * mostrara el estado operativo.
     */
    public function estaEnTallerYVencido(): bool
    {
        return $this->operativo === EstadoOperativoVehiculo::EnMantencion
            && $this->documental === EstadoTemporalDocumento::Vencido;
    }

    /**
     * @return array<string, mixed>
     */
    public function aArreglo(): array
    {
        return [
            'operativo' => $this->operativo->value,
            'operativo_etiqueta' => $this->operativo->etiqueta(),
            'documental' => $this->documental->value,
            'documental_etiqueta' => $this->documental->etiqueta(),
            'hay_algo_critico' => $this->hayAlgoCritico,
            'en_taller_y_vencido' => $this->estaEnTallerYVencido(),
            'alertas_pendientes' => $this->alertasPendientes,
            'mantencion_id' => $this->mantencionEnCurso?->id,
        ];
    }
}