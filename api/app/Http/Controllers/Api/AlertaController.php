<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\EstadoAlerta;
use App\Http\Controllers\Controller;
use App\Jobs\EnviarAlertaVencimiento;
use App\Models\AlertaDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja de alertas de vencimiento generadas.
 *
 * Solo se listan avisos que YA EXISTEN. Esta pantalla no calcula vencimientos
 * ni crea alertas: esa es la tarea programada. Si el listado está vacío, la
 * respuesta honesta es que no se ha generado nada, y por eso el panel muestra
 * además la fecha de la última generación.
 */
class AlertaController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $alertas = AlertaDocumento::query()
            ->with(['tipoDocumento', 'vehiculo', 'conductor'])
            ->when($peticion->filled('estado'), fn ($q) => $q->where('estado', $peticion->string('estado')))
            ->when($peticion->filled('vehiculo_id'), fn ($q) => $q->where('vehiculo_id', $peticion->string('vehiculo_id')))
            ->orderByDesc('created_at')
            ->paginate((int) config('flota.por_pagina', 25));

        $alertas->getCollection()->each(static function (AlertaDocumento $alerta): void {
            $alerta->setAttribute('resumen', $alerta->resumen());
            $alerta->setAttribute('estimada', $alerta->esEstimada());
        });

        return response()->json($alertas);
    }

    public function show(AlertaDocumento $alerta): JsonResponse
    {
        $alerta->load(['documento', 'tipoDocumento', 'vehiculo', 'conductor']);

        return response()->json($alerta);
    }

    /**
     * Reintento de envío.
     *
     * Genera UNA alerta nueva para el mismo periodo. No reutiliza la anterior,
     * y esa es la diferencia importante: si el reintento actualizara la
     * alerta original, el índice único de periodo no lo impediría y bastaría
     * con cambiar su estado para "reenviar", lo que haría imposible auditar
     * cuántos intentos hubo.
     */
    public function reintentar(Request $peticion, AlertaDocumento $alerta): JsonResponse
    {
        if ($alerta->estado !== EstadoAlerta::Error && $alerta->estado !== EstadoAlerta::Pendiente) {
            return response()->json([
                'mensaje' => 'La alerta ya fue entregada; no hay nada que reintentar.',
            ]);
        }

        $original = $alerta;

        $nueva = $alerta->replicate();
        $nueva->id = \Illuminate\Support\Str::uuid()->toString();
        $nueva->periodo_clave = $alerta->periodo_clave.':reintento:'.$alerta->id;
        $nueva->estado = EstadoAlerta::Pendiente->value;
        $nueva->enviada_at = null;
        $nueva->error_texto = null;
        $nueva->origen_generacion = 'reintento-manual';
        $nueva->save();

        if (config('flota.envio_activo', false) === true) {
            EnviarAlertaVencimiento::dispatch($nueva->id, $nueva->tenant_id);
        }

        return response()->json([
            'mensaje' => 'Se generó un reintento de la alerta.',
            'alerta_original_id' => $original->id,
            'alerta_nueva_id' => $nueva->id,
        ], 201);
    }
}