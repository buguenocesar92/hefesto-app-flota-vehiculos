<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionAlerta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Configuración de alertas: los plazos de aviso de la flota.
 *
 * Esta pantalla es la diferencia entre un sistema útil y uno que nadie mira.
 * El operador decide cuántos días antes quiere enterarse, y el sistema obedece.
 *
 * LO QUE NO HAY AQUÍ
 * ------------------
 * No hay ningún campo de plazo LEGAL. El sistema no decide cuánto dura un
 * permiso de circulación ni una revisión técnica: esa información la tiene que
 * confirmar Hefesto con un profesional del transporte o con el cliente, y
 * queda registrada con su fuente en `tipos_documento`.
 *
 * Aquí solo hay plazos de AVISO, que son una decisión operativa del cliente.
 */
class ConfiguracionAlertaController extends Controller
{
    public function index(): JsonResponse
    {
        $configuraciones = ConfiguracionAlerta::query()
            ->with('tipoDocumento')
            ->orderBy('umbral_dias')
            ->get();

        return response()->json([
            'datos' => $configuraciones,
            'umbrales_por_defecto' => config('flota.umbrales_alerta_dias'),
            'nota' => 'Estos son plazos de aviso, no plazos legales. '
                .'Los plazos legales los confirma Hefesto con un profesional del transporte o con el cliente.',
        ]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $this->autorizar();

        $datos = $peticion->validate([
            // NULL significa "todos los tipos de documento de la flota".
            'tipo_documento_id' => ['nullable', Rule::exists('tipos_documento', 'id')],
            'umbral_dias' => ['required', 'integer', 'min:1', 'max:3650'],
            'dias_insistencia_vencido' => ['nullable', 'integer', 'min:0', 'max:365'],
            'canal' => ['nullable', Rule::in(['correo', 'correo_interno', 'ninguno'])],
            'destinatarios' => ['nullable', 'array', 'max:50'],
            'destinatarios.*' => ['email'],
            'activa' => ['nullable', 'boolean'],
        ]);

        $configuracion = ConfiguracionAlerta::create($datos);

        return response()->json($configuracion, 201);
    }

    public function update(Request $peticion, ConfiguracionAlerta $configuracion): JsonResponse
    {
        $this->autorizar();

        $datos = $peticion->validate([
            'umbral_dias' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'dias_insistencia_vencido' => ['nullable', 'integer', 'min:0', 'max:365'],
            'canal' => ['nullable', Rule::in(['correo', 'correo_interno', 'ninguno'])],
            'destinatarios' => ['nullable', 'array', 'max:50'],
            'destinatarios.*' => ['email'],
            'activa' => ['nullable', 'boolean'],
        ]);

        $configuracion->update($datos);

        return response()->json($configuracion->refresh());
    }

    public function destroy(ConfiguracionAlerta $configuracion): JsonResponse
    {
        $this->autorizar();

        $configuracion->delete();

        return response()->json(['mensaje' => 'Umbral eliminado.']);
    }

    /**
     * Cambiar la configuración es una acción de administración de la flota.
     *
     * Va en el controlador y no solo en la Policy a propósito: un endpoint de
     * escritura tiene que negarse explícitamente, y no solo por una
     * consecuencia indirecta de una autorización.
     */
    private function autorizar(): void
    {
        abort_unless(
            auth()->user()?->rol?->puedeConfigurar() === true,
            403,
            'Cambiar la configuración de alertas es una tarea del administrador de la flota.'
        );
    }
}