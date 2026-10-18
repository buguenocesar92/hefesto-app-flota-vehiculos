<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conductor;
use App\Models\Documento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Conductores.
 *
 * Sus documentos se listan en la misma respuesta, porque la licencia del
 * conductor también vence y también genera alertas. Ocultar ese vencimiento en
 * una pantalla aparte es exactamente el tipo de cosa que el sistema viene a
 * evitar.
 */
class ConductorController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $conductores = Conductor::query()
            ->withCount('asignaciones')
            ->when($peticion->filled('activos'), fn ($q) => $q->where('activo', true))
            ->orderBy('nombre')
            ->paginate((int) config('flota.por_pagina', 25));

        return response()->json($conductores);
    }

    public function store(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'rut' => ['nullable', 'string', 'max:20'],
            'nombre' => ['required', 'string', 'max:160'],
            'apellido_paterno' => ['nullable', 'string', 'max:80'],
            'apellido_materno' => ['nullable', 'string', 'max:80'],
            'clase_licencia' => ['nullable', 'string', 'max:8'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'correo' => ['nullable', 'email', 'max:255'],
            'fecha_ingreso' => ['nullable', 'date'],
        ]);

        return response()->json(Conductor::create($datos), 201);
    }

    public function show(Conductor $conductor): JsonResponse
    {
        $conductor->load(['asignaciones.vehiculo', 'documentos.tipoDocumento', 'viajes']);

        $conductor->setAttribute(
            'documentos_estado',
            $conductor->documentos->map(static fn (Documento $documento) => [
                'id' => $documento->id,
                'tipo' => $documento->tipoDocumento?->nombre,
                'fecha_vencimiento' => $documento->fecha_vencimiento?->toDateString(),
                'dias_restantes' => $documento->diasRestantes(),
                'estado' => $documento->estadoTemporal()->value,
            ])
        );

        return response()->json($conductor);
    }
}