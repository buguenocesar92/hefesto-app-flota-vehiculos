<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FuenteKilometraje;
use App\Http\Controllers\Controller;
use App\Models\LecturaKilometraje;
use App\Models\Mantencion;
use App\Models\Vehiculo;
use App\Services\Kilometraje\KilometrajeIncoherente;
use App\Services\Kilometraje\RegistroDeKilometraje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kilometraje, CON SU FUENTE.
 *
 * Este endpoint es el que hace que el kilometraje no sea un campo libre.
 * No acepta solo un número: exige declarar de dónde sale.
 *
 * Si la fuente es `ajuste`, exige además el motivo y guarda el usuario
 * responsable. Y un kilometraje menor que el último no se acepta como lectura
 * normal: o es un error, o es un cambio de odómetro, y en el segundo caso hay
 * que decirlo.
 */
class KilometrajeController extends Controller
{
    public function __construct(private readonly RegistroDeKilometraje $registro) {}

    public function index(Request $peticion): JsonResponse
    {
        $lecturas = LecturaKilometraje::query()
            ->with(['vehiculo', 'conductor', 'mantencion'])
            ->when($peticion->filled('vehiculo_id'), fn ($q) => $q->where('vehiculo_id', $peticion->string('vehiculo_id')))
            ->when($peticion->filled('fuente'), fn ($q) => $q->where('fuente', $peticion->string('fuente')))
            ->orderByDesc('km')
            ->paginate((int) config('flota.por_pagina', 25));

        return response()->json($lecturas);
    }

    public function store(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'vehiculo_id' => ['required', Rule::exists('vehiculos', 'id')],
            'km' => ['required', 'integer', 'min:1'],
            'fuente' => ['required', Rule::in(FuenteKilometraje::valores())],
            'fecha' => ['required', 'date'],
            'conductor_id' => ['nullable', Rule::exists('conductores', 'id')],
            'viaje_id' => ['nullable', Rule::exists('viajes', 'id')],
            'carga_combustible_id' => ['nullable', Rule::exists('cargas_combustible', 'id')],
            'motivo_ajuste' => ['nullable', 'string', 'max:2000'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [
            'fuente.in' => 'La fuente debe indicar de dónde sale el kilometraje: '
                .implode(', ', FuenteKilometraje::valores()).'.',
        ]);

        $vehiculo = Vehiculo::query()->findOrFail($datos['vehiculo_id']);

        try {
            $lectura = $this->registro->registrar(
                vehiculo: $vehiculo,
                km: (int) $datos['km'],
                fuente: FuenteKilometraje::from($datos['fuente']),
                fecha: (string) $datos['fecha'],
                usuarioId: $peticion->user()?->id,
                conductorId: $datos['conductor_id'] ?? null,
                viajeId: $datos['viaje_id'] ?? null,
                cargaCombustibleId: $datos['carga_combustible_id'] ?? null,
                motivoAjuste: $datos['motivo_ajuste'] ?? null,
                observaciones: $datos['observaciones'] ?? null,
            );
        } catch (KilometrajeIncoherente $e) {
            // 422 y no 500: es un rechazo de negocio con una explicación
            // accionable, no una falla del sistema.
            return response()->json([
                'error' => [
                    'codigo' => 422,
                    'mensaje' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json($lectura, 201);
    }

    /**
     * Kilometraje que entregó el taller al cerrar una mantención.
     *
     * Ruta separada porque es un caso distinto: el dato viene del taller, no
     * del chofer, y queda registrado como tal. El mismo número cargado por la
     * puerta de arriba queda como `odometro`, y esa diferencia importa cuando
     * hay que reconstruir la historia del vehículo.
     */
    public function desdeMantencion(Request $peticion, Mantencion $mantencion): JsonResponse
    {
        $datos = $peticion->validate([
            'km_al_entregar' => ['required', 'integer', 'min:1'],
        ]);

        $lectura = $this->registro->registrarDesdeMantencion(
            vehiculo: $mantencion->vehiculo,
            mantencion: $mantencion,
            km: (int) $datos['km_al_entregar'],
            usuarioId: $peticion->user()?->id,
        );

        return response()->json($lectura, 201);
    }
}