<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentoRequest;
use App\Models\Documento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Documentos: el módulo central del producto.
 *
 * Este controlador es la puerta de entrada del problema que el dueño de la
 * flota reconoció: los documentos vencen en silencio y el negocio se detiene
 * de golpe.
 *
 * `vencimientos()` es el endpoint que importa. Devuelve los documentos
 * ordenados por urgencia, con los días restantes, la fecha efectiva y de
 * dónde sale esa fecha.
 */
class DocumentoController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $documentos = Documento::query()
            ->with(['tipoDocumento', 'vehiculo', 'conductor'])
            ->when($peticion->filled('vehiculo_id'), fn ($q) => $q->where('vehiculo_id', $peticion->string('vehiculo_id')))
            ->when($peticion->filled('conductor_id'), fn ($q) => $q->where('conductor_id', $peticion->string('conductor_id')))
            ->when($peticion->filled('tipo_documento_id'), fn ($q) => $q->where('tipo_documento_id', $peticion->string('tipo_documento_id')))
            ->orderBy('fecha_vencimiento')
            ->paginate((int) config('flota.por_pagina', 25));

        $documentos->getCollection()->each(static function (Documento $documento): void {
            $documento->setAttribute('dias_restantes', $documento->diasRestantes());
            $documento->setAttribute('estado_calculado', $documento->estadoTemporal()->value);
        });

        return response()->json($documentos);
    }

    /**
     * Documentos ordenados por urgencia.
     *
     * `dias` acota la ventana: por defecto, los que vencen dentro de 90 días
     * más todos los que ya vencieron. Un parámetro enorme trae el histórico
     * completo, que es lo que quiere una auditoría y no lo que quiere el
     * operador un martes.
     */
    public function vencimientos(Request $peticion): JsonResponse
    {
        $dias = min(3650, max(1, (int) $peticion->query('dias', 90)));

        $filas = [];

        Documento::query()
            ->where('estado', 'vigente')
            ->with(['tipoDocumento', 'vehiculo', 'conductor'])
            ->orderBy('fecha_vencimiento')
            ->each(function (Documento $documento) use (&$filas, $dias): void {
                $restantes = $documento->diasRestantes();

                if ($restantes === null || $restantes > $dias) {
                    return;
                }

                $efectiva = $documento->fechaVencimientoEfectiva();
                $estado = $documento->estadoTemporal();

                $filas[] = [
                    'id' => $documento->id,
                    'tipo_documento' => $documento->tipoDocumento?->nombre,
                    'vehiculo_patente' => $documento->vehiculo?->patente,
                    'conductor' => $documento->conductor?->nombreCompleto(),
                    'numero' => $documento->numero,
                    'fecha_emision' => $documento->fecha_emision?->toDateString(),
                    'fecha_vencimiento' => $documento->fecha_vencimiento?->toDateString(),
                    'fecha_efectiva' => $efectiva['fecha']?->toDateString(),
                    'origen_vencimiento' => $efectiva['origen'],
                    'estimada' => $efectiva['origen'] === 'regla_frecuencia',
                    'dias_restantes' => $restantes,
                    'estado' => $estado->value,
                    'estado_etiqueta' => $estado->etiqueta(),
                ];
            });

        usort($filas, static fn (array $a, array $b): int => $a['dias_restantes'] <=> $b['dias_restantes']);

        return response()->json([
            'datos' => $filas,
            'total' => count($filas),
            'ventana_dias' => $dias,
            'calculado_en' => now()->toIso8601String(),
        ]);
    }

    public function store(StoreDocumentoRequest $peticion): JsonResponse
    {
        $datos = $peticion->validated();

        $documento = Documento::create($datos);

        $documento->setAttribute('dias_restantes', $documento->diasRestantes());
        $documento->setAttribute('estado_calculado', $documento->estadoTemporal()->value);

        return response()->json($documento, 201);
    }

    public function show(Documento $documento): JsonResponse
    {
        $documento->load(['tipoDocumento', 'vehiculo', 'conductor', 'reglaFrecuencia', 'alertas']);

        $efectiva = $documento->fechaVencimientoEfectiva();

        $documento->setAttribute('dias_restantes', $documento->diasRestantes());
        $documento->setAttribute('estado_calculado', $documento->estadoTemporal()->value);
        $documento->setAttribute('fecha_efectiva', $efectiva['fecha']?->toDateString());
        $documento->setAttribute('origen_vencimiento', $efectiva['origen']);

        return response()->json($documento);
    }

    /**
     * Edición o renovación de un documento.
     *
     * El caso importante es la RENOVACIÓN: se actualiza la fecha de un
     * documento vigente. No se crea uno nuevo para no romper el historial, pero
     * la fecha nueva abre un ciclo de avisos distinto, porque la clave de
     * período incluye la fecha de vencimiento. Es decir: renovar el permiso de
     * circulación hace que los avisos futuros se vuelvan a generar, y los
     * avisos viejos no los bloquean.
     *
     * Cuando el documento anterior se reemplaza por uno nuevo, se marca como
     * `historico`: el sistema guarda la cadena de documentos del vehículo.
     */
    public function update(StoreDocumentoRequest $peticion, Documento $documento): JsonResponse
    {
        $datos = $peticion->validated();

        // Si se actualiza un documento que ya estaba vigente, el anterior de
        // ese mismo tipo pasa a histórico. Si no, el índice único
        // `documentos_un_vigente_por_tipo` rechazaría la operación.
        $reemplazado = Documento::query()
            ->where('vehiculo_id', $datos['vehiculo_id'] ?? $documento->vehiculo_id)
            ->where('conductor_id', $datos['conductor_id'] ?? $documento->conductor_id)
            ->where('tipo_documento_id', $datos['tipo_documento_id'] ?? $documento->tipo_documento_id)
            ->where('estado', 'vigente')
            ->whereKeyNot($documento->id)
            ->get();

        foreach ($reemplazado as $anterior) {
            $anterior->forceFill(['estado' => 'historico'])->save();
        }

        $documento->update($datos);
        $documento->refresh();

        $documento->setAttribute('dias_restantes', $documento->diasRestantes());
        $documento->setAttribute('estado_calculado', $documento->estadoTemporal()->value);

        return response()->json($documento);
    }

    /**
     * Anulación de un documento.
     *
     * No se borra: un documento anulado sigue siendo un hecho. Queda con
     * estado `anulado` y sale del cálculo de vencimientos, pero su historial
     * permanece.
     */
    public function destroy(Documento $documento): JsonResponse
    {
        $documento->forceFill(['estado' => 'anulado'])->save();

        return response()->json([
            'mensaje' => 'El documento quedó anulado y sale del cálculo de vencimientos.',
        ]);
    }
}