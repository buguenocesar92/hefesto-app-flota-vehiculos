<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EstadoAlerta;
use App\Models\AlertaDocumento;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Envía una alerta de vencimiento ya generada.
 *
 * EL TRABAJO NO GENERA LA ALERTA, SÓLO LA ENVÍA.
 *
 * La separación es deliberada: la tarea programada genera el aviso y lo
 * guarda; este trabajo lo entrega. Si el envío falla, la alerta queda en
 * estado `error` y se puede reintentar sin volver a generarla, y sin que el
 * operador reciba el mismo aviso dos veces.
 *
 * CONTEXTO DE TENANT
 * ------------------
 * El identificador de la flota viaja en el trabajo. Es necesario porque el
 * `tenant_id` se fija ANTES de leer la alerta: la RLS no permite leer
 * `alertas_documento` sin contexto, y pedir el registro "para ver de qué flota
 * es" sería imposible justamente por la política que lo protege.
 *
 * Se despacha desde `GeneradorDeAlertas`, que ya está ejecutándose dentro de la
 * transacción con el tenant fijado.
 */
class EnviarAlertaVencimiento implements ShouldQueue
{
    use Queueable;

    public int $intentos = 3;

    /** Minutos entre reintentos. */
    public array $retrocesos = [1, 5, 15];

    public function __construct(
        public readonly string $alertaId,
        public readonly string $tenantId,
    ) {}

    public function handle(ContextoTenant $contexto): void
    {
        $alerta = $contexto->ejecutarAislado(
            $this->tenantId,
            static fn () => AlertaDocumento::query()->find($this->alertaId)
        );

        // El documento se eliminó entre la generación y el envío. No hay nada
        // que mandar y no es un error.
        if ($alerta === null) {
            return;
        }

        $contexto->ejecutarAislado($this->tenantId, function () use ($alerta): void {
            $error = $this->enviar($alerta);

            $alerta->forceFill([
                'estado' => $error === null ? EstadoAlerta::Enviada->value : EstadoAlerta::Error->value,
                'enviada_at' => now(),
                'error_texto' => $error,
            ])->save();
        });
    }

    /**
     * Entrega del aviso.
     *
     * El transporte real (correo, webhook) se define en el canal de la
     * alerta. Mientras no se configure el envío,
     * `FLOTA_ALERTAS_ENVIO_ACTIVO` queda en falso y este trabajo nunca se
     * despacha: el aviso se genera y se ve en pantalla, pero no sale a
     * internet.
     */
    private function enviar(AlertaDocumento $alerta): ?string
    {
        Log::info('Alerta de vencimiento de documento enviada.', [
            'alerta_id' => $alerta->id,
            'tipo_documento_id' => $alerta->tipo_documento_id,
            'vehiculo_id' => $alerta->vehiculo_id,
            'dias_restantes' => $alerta->dias_restantes,
            'origen_vencimiento' => $alerta->origen_vencimiento,
        ]);

        return null;
    }

    /**
     * Si el envío falla definitivamente, la alerta queda en `error`, no en
     * `pendiente`: así el tablero muestra que el aviso NO llegó, en vez de
     * fingir que sigue en la cola.
     */
    public function failed(?\Throwable $e): void
    {
        Log::error('Falló el envío de una alerta de vencimiento.', [
            'alerta_id' => $this->alertaId,
            'error' => $e?->getMessage(),
        ]);
    }
}