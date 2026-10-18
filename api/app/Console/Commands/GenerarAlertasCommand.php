<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Alertas\GeneradorDeAlertas;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Tarea programada que genera las alertas de vencimiento.
 *
 * POR QUÉ ES UNA TAREA PROGRAMADA Y NO LA VISTA
 * --------------------------------------------
 * Una alerta que depende de que alguien abra una pantalla no es una alerta.
 * El permiso de circulación vence mañana y el chofer no va a mirar un tablero
 * hoy, pero el correo le llega igual.
 *
 * La programación vive en `routes/console.php` y su expresión de cron se
 * puede mover desde `.env` (`FLOTA_PROGRAMACION_ALERTAS`), sin tocar código.
 *
 * DOS SALVAGUARDAS CONTRA EJECUCIONES SIMULTÁNEAS
 * ----------------------------------------------
 * 1. `onOneServer()`: si por algún motivo se despliegan dos réplicas de la
 *    API, la tarea corre en una sola. Sin esto, cada réplica contaría los
 *    mismos vencimientos.
 * 2. `withoutOverlapping()`: impide que una corrida lenta se solape con la
 *    siguiente. El candado usa la caché (Redis), con la ventana de
 *    `FLOTA_CANDADO_ALERTAS_MINUTOS`.
 *
 * Y aun si las dos fallaran, la base sigue protegiendo: el índice único
 * (documento_id, periodo_clave) no permite dos avisos del mismo periodo. Las
 * tres capas apuntan al mismo objetivo, y la última es la que no puede
 * fallar.
 */
class GenerarAlertasCommand extends Command
{
    protected $signature = 'flota:generar-alertas
                            {--tenant= : Generar solo para una flota, por su slug}
                            {--fecha= : Tratar esta fecha como "hoy" (YYYY-MM-DD), para pruebas}
                            {--json : Salida en JSON, para consumo de otros sistemas}';

    protected $description = 'Genera las alertas de vencimiento de documentos de la flota.';

    public function handle(GeneradorDeAlertas $generador): int
    {
        $hoy = $this->fechaDeReferencia();

        $resultado = $this->opcion('tenant') !== null
            ? $this->generarParaUnaFlota($generador, (string) $this->opcion('tenant'), $hoy)
            : $generador->generarParaTodas($hoy);

        if ($this->opcion('json')) {
            $this->line((string) json_encode($resultado->aArreglo(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Generación de alertas de vencimiento terminada.');
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Documentos vigentes revisados', $resultado->documentosRevisados],
                ['Documentos ya vencidos', $resultado->documentosVencidos],
                ['Documentos sin fecha de vencimiento', $resultado->documentosSinFecha],
                ['Alertas nuevas', $resultado->alertasGeneradas],
                ['Alertas que ya existían (no duplicadas)', $resultado->alertasOmitidasPorDuplicado],
                ['Alertas despachadas a la cola', $resultado->alertasDespachadas],
            ]
        );

        // La segunda línea "Alertas que ya existían" es la prueba operativa de
        // que la tarea es idempotente: en una corrida de mantenimiento, con
        // datos ya avisados, debe ser igual a la cantidad de alertas de la
        // corrida anterior, y "Alertas nuevas" debe ser cero.
        if ($resultado->alertasGeneradas === 0 && $resultado->alertasOmitidasPorDuplicado > 0) {
            $this->line('Sin novedades: todas las alertas de este período ya estaban generadas.');
        }

        return self::SUCCESS;
    }

    /**
     * Fecha con la que se calcula "hoy".
     *
     * Existe la opción `--fecha` para poder probar el comportamiento en una
     * fecha concreta y para reconstruir el cálculo de un día pasado. Sin
     * ella, el comando usa la fecha real.
     */
    private function fechaDeReferencia(): ?Carbon
    {
        $valor = $this->opcion('fecha');

        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $valor)->startOfDay();
        } catch (\Throwable) {
            $this->error("La fecha «{$valor}» no es válida. Se espera el formato YYYY-MM-DD.");

            // Falla de verdad y no sigue con la fecha de hoy "por si acaso":
            // generar avisos de la fecha equivocada es peor que no generar.
            throw new \RuntimeException('La fecha de referencia no es válida.');
        }
    }

    private function generarParaUnaFlota(
        GeneradorDeAlertas $generador,
        string $slug,
        ?Carbon $hoy,
    ): \App\Services\Alertas\ResultadoGeneracionAlertas {
        $tenant = \App\Models\Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("No existe ninguna flota con el identificador «{$slug}».");

            return new \App\Services\Alertas\ResultadoGeneracionAlertas;
        }

        return $generador->generarParaTenant($tenant->id, $hoy);
    }

    private function opcion(string $nombre): mixed
    {
        return $this->option($nombre);
    }
}