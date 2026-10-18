<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehiculoRequest;
use App\Models\Vehiculo;
use App\Services\Vehiculos\EstadoOperativoVehiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vehículos.
 *
 * Cada respuesta incluye los DOS estados del vehículo (operativo y
 * documental). No es un detalle de formato: es lo que impide que un vehículo
 * en el taller con la revisión vencida se vea solo como "en mantención".
 */
class VehiculoController extends Controller
{
    public function __construct(private readonly EstadoOperativoVehiculo $estados) {}

    public function index(Request $peticion): JsonResponse
    {
        $vehiculos = Vehiculo::query()
            ->with(['tipoServicio', 'centroCosto'])
            ->when($peticion->filled('patente'), fn ($q) => $q->where('patente', 'ilike', '%'.$peticion->string('patente').'%'))
            ->when($peticion->boolean('solo_activos'), fn ($q) => $q->where('activo', true))
            ->orderBy('patente')
            ->paginate((int) config('flota.por_pagina', 25));

        $vehiculos->getCollection()->each(function (Vehiculo $vehiculo): void {
            $estado = $this->estados->paraVehiculo($vehiculo);

            // El estado se agrega al recurso en lugar de guardarlo, para que
            // no exista un único estado que esconda al otro.
            $vehiculo->setAttribute('estado_calculado', $estado->aArreglo());
            $vehiculo->setAttribute('kilometraje_actual', $vehiculo->kilometrajeActual());
        });

        return response()->json($vehiculos);
    }

    public function store(StoreVehiculoRequest $peticion): JsonResponse
    {
        $vehiculo = Vehiculo::create($peticion->validated());
        $vehiculo->setAttribute('estado_calculado', $this->estados->paraVehiculo($vehiculo)->aArreglo());

        return response()->json($vehiculo, 201);
    }

    public function show(Vehiculo $vehiculo): JsonResponse
    {
        $vehiculo->load(['tipoServicio', 'centroCosto', 'documentos.tipoDocumento']);

        $estado = $this->estados->paraVehiculo($vehiculo);

        $vehiculo->setAttribute('estado_calculado', $estado->aArreglo());
        $vehiculo->setAttribute('kilometraje_actual', $vehiculo->kilometrajeActual());

        // El detalle de cada documento viaja con su estado propio: el
        // resumen dice "vencido", y la lista dice cuál de los dos.
        $vehiculo->setAttribute('documentos_estado', $estado->documentos->map(
            static fn ($documento) => [
                'id' => $documento->id,
                'tipo' => $documento->tipoDocumento?->nombre,
                'fecha_vencimiento' => $documento->fecha_vencimiento?->toDateString(),
                'fecha_calculada' => $documento->fechaVencimientoCalculada()?->toDateString(),
                'origen' => $documento->fechaVencimientoEfectiva()['origen'],
                'dias_restantes' => $documento->diasRestantes(),
                'estado' => $documento->estadoTemporal()->value,
            ]
        ));

        return response()->json($vehiculo);
    }

    public function update(StoreVehiculoRequest $peticion, Vehiculo $vehiculo): JsonResponse
    {
        $vehiculo->update($peticion->validated());

        $vehiculo->setAttribute('estado_calculado', $this->estados->paraVehiculo($vehiculo)->aArreglo());

        return response()->json($vehiculo);
    }

    /**
     * Baja de un vehículo.
     *
     * No se borra de verdad: queda el historial de documentos, mantenciones y
     * viajes que hay que poder responderle a un fiscal dos años después. El
     * vehículo se marca `activo = false` y conserva todo.
     */
    public function destroy(Vehiculo $vehiculo): JsonResponse
    {
        $vehiculo->forceFill([
            'activo' => false,
            'retirado_at' => now(),
        ])->save();

        return response()->json([
            'mensaje' => 'El vehículo se retiró de la flota. Su historial se conserva.',
        ]);
    }
}