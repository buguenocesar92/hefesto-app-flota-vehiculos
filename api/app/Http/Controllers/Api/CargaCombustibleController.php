<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FuenteKilometraje;
use App\Http\Controllers\Controller;
use App\Models\CargaCombustible;
use App\Models\Vehiculo;
use App\Services\Combustible\CalculadoraDeRendimiento;
use App\Services\Kilometraje\RegistroDeKilometraje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Combustible y costos.
 *
 * El total se recalcula SIEMPRE desde litros y precio unitario. No se confía
 * en lo que venga en el formulario: el rendimiento que se muestra después
 * tiene que ser coherente con las cifras que aparecen al lado.
 */
class CargaCombustibleController extends Controller
{
    public function __construct(
        private readonly CalculadoraDeRendimiento $rendimiento,
        private readonly RegistroDeKilometraje $kilometraje,
    ) {}

    public function index(Request $peticion): JsonResponse
    {
        $cargas = CargaCombustible::query()
            ->with(['vehiculo', 'conductor'])
            ->when($peticion->filled('vehiculo_id'), fn ($q) => $q->where('vehiculo_id', $peticion->string('vehiculo_id')))
            ->orderByDesc('fecha')
            ->paginate((int) config('flota.por_pagina', 25));

        $cargas->getCollection()->each(function (CargaCombustible $carga): void {
            $calculado = $this->rendimiento->paraCarga($carga);

            $carga->setAttribute('total_calculado', $carga->totalCalculado());
            $carga->setAttribute(
                'rendimiento',
                $calculado?->aArreglo()
            );
        });

        return response()->json($cargas);
    }

    /**
     * Registro de una carga.
     *
     * Además de guardar la carga, genera la LECTURA DE KILOMETRAJE con fuente
     * `carga_combustible`. Cargar combustible es una de las tres maneras
     * legítimas de saber cuánto lleva el vehículo, y si no se anotara, el
     * odómetro quedaría desactualizado hasta la próxima revisión del taller.
     */
    public function store(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'vehiculo_id' => ['required', Rule::exists('vehiculos', 'id')],
            'conductor_id' => ['nullable', Rule::exists('conductores', 'id')],
            'fecha' => ['required', 'date'],
            'litros' => ['required', 'numeric', 'min:0.01', 'max:2000'],
            'precio_unitario' => ['required', 'numeric', 'min:0'],
            'tipo_combustible' => ['nullable', 'string', 'max:40'],
            'estacion' => ['nullable', 'string', 'max:160'],
            'km_lectura' => ['required', 'integer', 'min:1'],
            'numero_boleta' => ['nullable', 'string', 'max:60'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $vehiculo = Vehiculo::query()->findOrFail($datos['vehiculo_id']);

        // Carga y lectura se escriben juntas o no se escriben. Si el
        // kilometraje indicado es incoherente con las lecturas anteriores, la
        // carga tampoco queda: no tiene sentido tener el gasto sin el
        // odómetro que lo justifica, y un gasto huérfano después obliga a
        // descifrar de dónde salió.
        [$carga, $lectura] = DB::transaction(function () use ($datos, $vehiculo, $peticion): array {
            $carga = CargaCombustible::create($datos + [
                'total' => (int) round((float) $datos['litros'] * (float) $datos['precio_unitario']),
            ]);

            $lectura = $this->kilometraje->registrar(
                vehiculo: $vehiculo,
                km: (int) $datos['km_lectura'],
                fuente: FuenteKilometraje::CargaCombustible,
                fecha: substr((string) $datos['fecha'], 0, 10),
                usuarioId: $peticion->user()?->id,
                conductorId: $datos['conductor_id'] ?? null,
                cargaCombustibleId: $carga->id,
            );

            return [$carga, $lectura];
        });

        $carga->setAttribute('lectura_kilometraje_id', $lectura->id);
        $carga->setAttribute('rendimiento', $this->rendimiento->paraCarga($carga)?->aArreglo());

        return response()->json($carga, 201);
    }

    /**
     * Rendimiento calculado del vehículo.
     *
     * Siempre se devuelve `calculado: true`. Si no hay datos suficientes, se
     * devuelve un null con la explicación, en vez de un número inventado.
     */
    public function rendimiento(Request $peticion): JsonResponse
    {
        $vehiculo = Vehiculo::query()->findOrFail($peticion->string('vehiculo_id'));

        $resultado = $this->rendimiento->promedioPorVehiculo($vehiculo);

        return response()->json([
            'vehiculo_id' => $vehiculo->id,
            'patente' => $vehiculo->patente,
            'calculado' => true,
            'rendimiento' => $resultado?->aArreglo(),
            'motivo_sin_datos' => $resultado === null
                ? 'Hacen falta al menos dos cargas de combustible del mismo vehículo para calcular el rendimiento.'
                : null,
        ]);
    }
}