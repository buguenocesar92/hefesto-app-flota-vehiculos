<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\EstadoAlerta;
use App\Enums\EstadoTemporalDocumento;
use App\Http\Controllers\Controller;
use App\Models\AlertaDocumento;
use App\Models\Documento;
use App\Models\Mantencion;
use App\Models\Tenant;
use App\Models\Vehiculo;
use App\Services\Vehiculos\EstadoOperativoVehiculo;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tablero de vencimientos: la pantalla que el operador abre cada mañana.
 *
 * Responde las preguntas en el orden en que importan:
 *   1. ¿Qué está vencido HOY? (primero, porque ya es un problema)
 *   2. ¿Qué vence esta semana? (hay tiempo de actuar)
 *   3. ¿Cuándo se corrió la última generación de avisos?
 *
 * La tercera está porque una alerta que llega tarde no sirve: si el operador
 * no sabe cuándo se calculó, no puede saber si lo que ve está al día. Por eso
 * el tablero muestra la hora explícitamente, incluso cuando todo está al día.
 */
class PanelController extends Controller
{
    public function __construct(private readonly EstadoOperativoVehiculo $estados) {}

    /**
     * Resumen para la portada del tablero.
     */
    public function resumen(Request $peticion): JsonResponse
    {
        $hoy = Carbon::now();

        $documentos = Documento::query()->where('estado', 'vigente')->get();

        $porEstado = [
            EstadoTemporalDocumento::Vencido->value => 0,
            EstadoTemporalDocumento::PorVencer->value => 0,
            EstadoTemporalDocumento::Vigente->value => 0,
            EstadoTemporalDocumento::SinFecha->value => 0,
        ];

        foreach ($documentos as $documento) {
            $porEstado[$documento->estadoTemporal()->value]++;
        }

        // El caso que el dueño de la flota señaló: en mantención Y con
        // documentos vencidos. Se cuenta aparte para que no desaparezca
        // detrás del total de vencidos.
        $enTallerYVencidos = 0;

        Vehiculo::query()
            ->where('activo', true)
            ->each(function (Vehiculo $vehiculo) use (&$enTallerYVencidos): void {
                if ($this->estados->paraVehiculo($vehiculo)->estaEnTallerYVencido()) {
                    $enTallerYVencidos++;
                }
            });

        return response()->json([
            'vehiculos_activos' => Vehiculo::query()->where('activo', true)->count(),
            'documentos_vigentes' => $documentos->count(),
            'documentos' => $porEstado,
            'vehiculos_en_taller_y_vencidos' => $enTallerYVencidos,
            'mant_en_proceso' => Mantencion::query()->where('estado', 'en_proceso')->count(),
            'alertas_pendientes' => AlertaDocumento::query()
                ->whereIn('estado', [EstadoAlerta::Pendiente->value, EstadoAlerta::Error->value])
                ->count(),
            'ultima_generacion_alertas' => Tenant::query()
                ->where('id', $peticion->user()->tenant_id)
                ->value('alertas_generadas_at'),
            'fecha_referencia' => $hoy->toDateString(),
        ]);
    }

    /**
     * Listado de vencimientos, ordenado por días restantes.
     *
     * Los vencidos van primero, aunque su "días restantes" sea negativo: es
     * un orden de urgencia, no de fecha.
     */
    public function vencimientos(Request $peticion): JsonResponse
    {
        $dias = (int) $peticion->query('dias', 90);

        $documentos = Documento::query()
            ->where('estado', 'vigente')
            ->with(['tipoDocumento', 'vehiculo', 'conductor', 'reglaFrecuencia'])
            ->get();

        $filas = [];

        foreach ($documentos as $documento) {
            $restantes = $documento->diasRestantes();

            if ($restantes === null || $restantes > $dias) {
                continue;
            }

            $filas[] = [
                'documento_id' => $documento->id,
                'tipo' => $documento->tipoDocumento?->nombre,
                'vehiculo' => $documento->vehiculo?->patente,
                'conductor' => $documento->conductor?->nombreCompleto(),
                'fecha_vencimiento' => $documento->fecha_vencimiento?->toDateString(),
                'fecha_vencimiento_calculada' => $documento->fechaVencimientoCalculada()?->toDateString(),
                'origen' => $documento->fechaVencimientoEfectiva()['origen'],
                'dias_restantes' => $restantes,
                'estado' => $documento->estadoTemporal()->value,
                'estado_etiqueta' => $documento->estadoTemporal()->etiqueta(),
            ];
        }

        // Urgente primero: vencidos, luego los más próximos.
        usort($filas, static fn (array $a, array $b): int => $a['dias_restantes'] <=> $b['dias_restantes']);

        return response()->json([
            'datos' => $filas,
            'total' => count($filas),
            'dias_consultados' => $dias,
        ]);
    }

    /**
     * Cuándo se corrió la última generación de alertas.
     *
     * Se expone por separado para que la interfaz pueda mostrarlo sin
     * inventarse nada: si nunca se ejecutó, la respuesta lo dice.
     */
    public function alertasGeneradas(Request $peticion): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($peticion->user()->tenant_id);

        return response()->json([
            'ultima_generacion' => $tenant->alertas_generadas_at?->toIso8601String(),
            'nunca_ejecutada' => $tenant->alertas_generadas_at === null,
            'programacion' => config('flota.programacion_alertas'),
            'zona_horaria' => config('flota.zona_horaria'),
        ]);
    }
}