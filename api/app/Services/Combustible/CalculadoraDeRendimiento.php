<?php

declare(strict_types=1);

namespace App\Services\Combustible;

use App\Models\CargaCombustible;
use App\Models\Vehiculo;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Cálculo de rendimiento de combustible.
 *
 * EL RENDIMIENTO NO SE GUARDA. Se calcula, siempre, sobre las cargas
 * consecutivas del vehículo.
 *
 * Por qué: si `rendimiento_km_por_litro` fuera una columna, su corrección
 * dependería de que alguien lo escribiera. Con el cálculo, es imposible que
 * esté desactualizado, porque se recalcula cada vez que se consulta y si las
 * lecturas cambian, el rendimiento cambia con ellas.
 *
 * El tramo se mide entre la carga anterior y esta: kilómetros recorridos
 * divididos por los litros puestos. Con una sola carga no hay tramo, y el
 * sistema NO inventa un número: devuelve null y la interfaz dice que falta una
 * carga anterior para poder calcularlo.
 */
class CalculadoraDeRendimiento
{
    private float $litrosMinimos;

    public function __construct(?float $litrosMinimos = null)
    {
        $this->litrosMinimos = $litrosMinimos
            ?? (float) config('flota.litros_minimos_rendimiento', 5);
    }

    /**
     * Rendimiento de una carga concreta, comparada con la anterior del mismo
     * vehículo.
     */
    public function paraCarga(CargaCombustible $carga): ?RendimientoConsumo
    {
        $anterior = $this->cargaAnterior($carga);

        if ($anterior === null) {
            return null;
        }

        $kilometros = (int) $carga->km_lectura - (int) $anterior->km_lectura;
        $litros = (float) $carga->litros;

        if ($kilometros <= 0 || $litros <= 0) {
            return null;
        }

        if ($litros < $this->litrosMinimos) {
            // Con muy poco combustible el rendimiento es ruido: dos litros y
            // una suma de redondeo ya cambian el número.
            return null;
        }

        return new RendimientoConsumo(
            kilometrosRecorridos: $kilometros,
            litrosCargados: $litros,
            kilometrosPorLitro: round($kilometros / $litros, 2),
            litrosPorCienKilometros: round(($litros / $kilometros) * 100, 2),
            costoTotal: $this->costoDe([$carga]),
            desdeCarga: $anterior,
            hastaCarga: $carga,
            cantidadCargas: 2,
        );
    }

    /**
     * Rendimiento promedio del vehículo en una ventana de tiempo.
     *
     * Pondera por litros, no por cantidad de cargas: promediar "20 km/l" con
     * "6 km/l" dando el mismo peso a las dos produce un número que no
     * corresponde a ningún periodo real.
     */
    public function promedioPorVehiculo(
        Vehiculo $vehiculo,
        ?Carbon $desde = null,
        ?Carbon $hasta = null,
    ): ?RendimientoConsumo {
        $cargas = $vehiculo->cargasCombustible()
            ->when($desde !== null, fn ($q) => $q->where('fecha', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('fecha', '<=', $hasta))
            ->orderBy('fecha')
            ->get();

        return $this->promoverDeCargas($cargas);
    }

    /**
     * Rendimiento promedio sobre un conjunto de cargas ya ordenadas.
     *
     * @param  Collection<int, CargaCombustible>  $cargas
     */
    public function promoverDeCargas(Collection $cargas): ?RendimientoConsumo
    {
        // Con menos de dos cargas no hay ningún tramo medible.
        if ($cargas->count() < 2) {
            return null;
        }

        $primera = $cargas->first();
        $ultima = $cargas->last();

        $kilometros = (int) $ultima->km_lectura - (int) $primera->km_lectura;
        $litros = (float) $cargas->sum(fn (CargaCombustible $c): float => (float) $c->litros);

        if ($kilometros <= 0 || $litros <= 0) {
            return null;
        }

        return new RendimientoConsumo(
            kilometrosRecorridos: $kilometros,
            litrosCargados: $litros,
            kilometrosPorLitro: round($kilometros / $litros, 2),
            litrosPorCienKilometros: round(($litros / $kilometros) * 100, 2),
            costoTotal: $this->costoDe($cargas),
            desdeCarga: $primera,
            hastaCarga: $ultima,
            cantidadCargas: $cargas->count(),
        );
    }

    /**
     * Costo total del conjunto, recalculado desde litros y precio unitario.
     *
     * No se confía en la columna `total`: también se puede cargar desde el
     * formulario, y el rendimiento tiene que ser coherente con las cifras que
     * se están mostrando.
     *
     * @param  iterable<CargaCombustible>  $cargas
     */
    private function costoDe(iterable $cargas): int
    {
        $suma = 0.0;

        foreach ($cargas as $carga) {
            $suma += (float) $carga->litros * (float) $carga->precio_unitario;
        }

        return (int) round($suma);
    }

    /** La carga inmediatamente anterior a esta, en el mismo vehículo. */
    private function cargaAnterior(CargaCombustible $carga): ?CargaCombustible
    {
        return CargaCombustible::query()
            ->where('vehiculo_id', $carga->vehiculo_id)
            ->where('fecha', '<', $carga->fecha)
            ->orderByDesc('fecha')
            ->first();
    }
}