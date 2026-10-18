<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Estado del servicio.
 *
 * No dice NADA sobre los datos de ninguna flota: ni cuántos vehículos hay, ni
 * nombres, ni identificadores. Solo responde si el proceso está vivo y si la
 * base responde.
 *
 * Es una ruta sin autenticación a propósito: un monitor de disponibilidad no
 * debería necesitar credenciales. Por eso no expone nada útil para un
 * atacante, y por eso no consulta ninguna tabla protegida por RLS.
 */
class SaludController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $baseResponde = true;

        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $baseResponde = false;
        }

        return response()->json([
            'servicio' => 'flota-vehiculos',
            'estado' => $baseResponde ? 'ok' : 'degradado',
            'version' => '1.0.0',
        ], $baseResponde ? 200 : 503);
    }
}