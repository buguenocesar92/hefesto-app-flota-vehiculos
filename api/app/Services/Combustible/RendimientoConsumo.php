<?php

declare(strict_types=1);

namespace App\Services\Combustible;

use App\Models\CargaCombustible;

/**
 * Rendimiento calculado de un tramo.
 *
 * Objeto de solo lectura con las cifras ya redondeadas. No se guarda en la
 * base: es el resultado de un cálculo sobre las cargas reales.
 */
final class RendimientoConsumo
{
    public function __construct(
        public readonly int $kilometrosRecorridos,
        public readonly float $litrosCargados,
        public readonly float $kilometrosPorLitro,
        public readonly float $litrosPorCienKilometros,
        public readonly int $costoTotal,
        public readonly CargaCombustible $desdeCarga,
        public readonly CargaCombustible $hastaCarga,
        public readonly int $cantidadCargas = 2,
    ) {}

    /**
     * @return array<string, float|int>
     */
    public function aArreglo(): array
    {
        return [
            'kilometros_recorridos' => $this->kilometrosRecorridos,
            'litros_cargados' => $this->litrosCargados,
            'kilometros_por_litro' => $this->kilometrosPorLitro,
            'litros_por_100_kilometros' => $this->litrosPorCienKilometros,
            'costo_total' => $this->costoTotal,
            'cantidad_cargas' => $this->cantidadCargas,
        ];
    }

    /** Advertencia honesta para la interfaz cuando el número es bajo. */
    public function esBajo(): bool
    {
        return $this->kilometrosPorLitro < 6.0;
    }
}