<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mantencion;
use App\Models\Vehiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mantenciones.
 *
 * Módulo de apoyo. Agenda por kilometraje o por fecha, con historial, costo y
 * taller asociado.
 *
 * La validación por tipo importa: una mantención agendada "por kilometraje"
 * sin kilometraje no se puede ordenar en ningún tablero, y una "por fecha" sin
 * fecha tampoco. La regla se aplica acá y también en la base
 * (restricción `mantenciones_declara_su_programacion`).
 */
class MantencionController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $mantenciones = Mantencion::query()
            ->with(['vehiculo', 'taller'])
            ->when($peticion->filled('vehiculo_id'), fn ($q) => $q->where('vehiculo_id', $peticion->string('vehiculo_id')))
            ->when($peticion->filled('estado'), fn ($q) => $q->where('estado', $peticion->string('estado')))
            ->orderByRaw("CASE estado WHEN 'en_proceso' THEN 0 WHEN 'programada' THEN 1 ELSE 2 END")
            ->orderBy('fecha_programada')
            ->paginate((int) config('flota.por_pagina', 25));

        return response()->json($mantenciones);
    }

    public function store(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'vehiculo_id' => ['required', Rule::exists('vehiculos', 'id')],
            'taller_id' => ['nullable', Rule::exists('talleres', 'id')],
            'descripcion' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::in(['kilometraje', 'fecha', 'mixto'])],
            'km_programado' => ['nullable', 'integer', 'min:0'],
            'fecha_programada' => ['nullable', 'date'],
            'km_proximo' => ['nullable', 'integer', 'min:0'],
            'fecha_proxima' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->validarProgramacion($datos);

        $mantencion = Mantencion::create($datos + ['estado' => 'programada']);

        return response()->json($mantencion, 201);
    }

    public function show(Mantencion $mantencion): JsonResponse
    {
        $mantencion->load(['vehiculo', 'taller', 'lecturas']);

        return response()->json($mantencion);
    }

    public function update(Request $peticion, Mantencion $mantencion): JsonResponse
    {
        $datos = $peticion->validate([
            'estado' => ['required', Rule::in(['programada', 'en_proceso', 'completada', 'cancelada'])],
            'fecha_ejecucion' => ['nullable', 'date'],
            'km_al_entregar' => ['nullable', 'integer', 'min:0'],
            'costo' => ['nullable', 'integer', 'min:0'],
            'km_proximo' => ['nullable', 'integer', 'min:0'],
            'fecha_proxima' => ['nullable', 'date'],
            'taller_id' => ['nullable', Rule::exists('talleres', 'id')],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        // Una mantención completada sin fecha de ejecución no tiene sentido
        // para ningún reporte posterior.
        if (($datos['estado'] ?? null) === 'completada' && ($datos['fecha_ejecucion'] ?? null) === null) {
            return response()->json([
                'error' => [
                    'codigo' => 422,
                    'mensaje' => 'Para marcar la mantención como completada hay que indicar la fecha de ejecución.',
                ],
            ], 422);
        }

        $mantencion->update($datos);

        return response()->json($mantencion->refresh());
    }

    /**
     * La programación tiene que ser coherente con el tipo elegido.
     *
     * @param  array<string, mixed>  $datos
     */
    private function validarProgramacion(array $datos): void
    {
        $tipo = $datos['tipo'] ?? null;

        $problema = match ($tipo) {
            'kilometraje' => ($datos['km_programado'] ?? null) === null
                ? 'Una mantención por kilometraje necesita el kilometraje en que se hace.'
                : null,
            'fecha' => ($datos['fecha_programada'] ?? null) === null
                ? 'Una mantención por fecha necesita la fecha en que se hace.'
                : null,
            'mixto' => ($datos['km_programado'] ?? null) === null && ($datos['fecha_programada'] ?? null) === null
                ? 'Una mantención mixta necesita al menos el kilometraje o la fecha.'
                : null,
            default => 'El tipo de mantenimiento no es válido.',
        };

        if ($problema !== null) {
            throw new \Illuminate\Validation\ValidationException(
                ['tipo' => [$problema]]
            );
        }
    }
}