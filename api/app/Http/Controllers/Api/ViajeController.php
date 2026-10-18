<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Viaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Viajes.
 *
 * Un viaje puede haber ocurrido sin asignación formal: en la práctica alguien
 * condujo, y el registro existe igual. Por eso `conductor_id` es nullable y no
 * se exige que haya una asignación vigente.
 */
class ViajeController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $viajes = Viaje::query()
            ->with(['vehiculo', 'conductor'])
            ->when($peticion->filled('vehiculo_id'), fn ($q) => $q->where('vehiculo_id', $peticion->string('vehiculo_id')))
            ->when($peticion->filled('conductor_id'), fn ($q) => $q->where('conductor_id', $peticion->string('conductor_id')))
            ->orderByDesc('fecha_salida')
            ->paginate((int) config('flota.por_pagina', 25));

        return response()->json($viajes);
    }

    public function store(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'vehiculo_id' => ['required', Rule::exists('vehiculos', 'id')],
            'conductor_id' => ['nullable', Rule::exists('conductores', 'id')],
            'asignacion_id' => ['nullable', Rule::exists('asignaciones', 'id')],
            'origen' => ['nullable', 'string', 'max:160'],
            'destino' => ['nullable', 'string', 'max:160'],
            'proposito' => ['nullable', 'string', 'max:255'],
            'fecha_salida' => ['nullable', 'date'],
            'estado' => ['nullable', Rule::in(['planificado', 'en_curso', 'finalizado', 'cancelado'])],
            'km_salida' => ['nullable', 'integer', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(Viaje::create($datos), 201);
    }

    /**
     * Cierre del viaje, con kilometraje de retorno.
     *
     * Cuando se informa el kilometraje de retorno, también queda disponible
     * para registrar la lectura de kilometraje correspondiente. El sistema no
     * la crea solo: el odómetro se lee, no se deduce.
     */
    public function update(Request $peticion, Viaje $viaje): JsonResponse
    {
        $datos = $peticion->validate([
            'fecha_retorno' => ['nullable', 'date', 'after_or_equal:fecha_salida'],
            'km_retorno' => ['nullable', 'integer', 'min:0'],
            'estado' => ['required', Rule::in(['planificado', 'en_curso', 'finalizado', 'cancelado'])],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $viaje->update($datos);

        return response()->json($viaje->refresh());
    }
}