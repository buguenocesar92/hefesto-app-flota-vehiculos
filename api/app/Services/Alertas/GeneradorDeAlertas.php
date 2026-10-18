<?php

declare(strict_types=1);

namespace App\Services\Alertas;

use App\Enums\EstadoAlerta;
use App\Jobs\EnviarAlertaVencimiento;
use App\Models\AlertaDocumento;
use App\Models\ConfiguracionAlerta;
use App\Models\Documento;
use App\Models\Tenant;
use App\Services\Tenancy\ContextoTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generador de alertas de vencimiento.
 *
 * ESTE ES EL CORAZÓN DEL PRODUCTO. Un vehículo tiene el permiso de circulación
 * vencido mañana y el chofer no lo sabe; a otro se le venció el seguro y nadie
 * se enteró hasta el accidente. Esto es lo que evita que eso pase.
 *
 * POR QUÉ SE GENERA DESDE AQUÍ Y NO DESDE LA VISTA
 * ------------------------------------------------
 * Una alerta que depende de que alguien mire una pantalla no es una alerta: es
 * un recordatorio con pasos. La generación ocurre en una tarea programada
 * (`php artisan flota:generar-alertas`), no al cargar el tablero.
 *
 * POR QUÉ NO SE DUPLICA AL REEJECUTAR
 * -----------------------------------
 * Cada alerta lleva una `periodo_clave` DETERMINISTA (ver
 * `AlertaDocumento::calcularPeriodoClave`) y la tabla tiene un índice ÚNICO
 * sobre (documento_id, periodo_clave). La inserción usa `insertOrIgnore`,
 * que en PostgreSQL compila a `ON CONFLICT DO NOTHING`.
 *
 * Consecuencia: reejecutar la tarea las veces que haga falta no duplica
 * nada. No es que el generador "se acuerde" de lo que hizo; es que la base no
 * permite el duplicado. Y como la clave incluye la fecha de vencimiento,
 * renovar el documento abre un periodo nuevo y los avisos vuelven a salir,
 * en vez de quedar bloqueados para siempre por los del ciclo anterior.
 *
 * SOBRE LOS PLAZOS
 * ----------------
 * Los umbrales vienen de `configuraciones_alerta`, que es donde el cliente
 * decide cuántos días antes quiere que le avisen. Son plazos de AVISO, no
 * plazos legales. El sistema jamás deduce una fecha legal de vencimiento: usa
 * la que viene impresa en el documento, o la que calcula una regla de
 * frecuencia CONFIRMADA, y en ese caso marca el aviso como estimado.
 */
class GeneradorDeAlertas
{
    public function __construct(private readonly ContextoTenant $contexto) {}

    /**
     * Genera las alertas de una flota completa.
     */
    public function generarParaTenant(string $tenantId, ?Carbon $hoy = null): ResultadoGeneracionAlertas
    {
        return $this->contexto->ejecutarAislado(
            $tenantId,
            fn (): ResultadoGeneracionAlertas => $this->generar($hoy)
        );
    }

    /**
     * Genera las alertas de todas las flotas activas.
     *
     * Cada flota se recorre en su propia transacción con su tenant fijado.
     * El recorrido de flotas NO lleva RLS: `tenants` es justamente la tabla
     * que se lee sin contexto de tenant.
     */
    public function generarParaTodas(?Carbon $hoy = null): ResultadoGeneracionAlertas
    {
        $total = new ResultadoGeneracionAlertas;

        Tenant::query()
            ->where('activo', true)
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($hoy, $total): void {
                $total->sumar($this->generarParaTenant($tenant->id, $hoy));
            });

        return $total;
    }

    /**
     * Cuerpo del generador. Siempre se ejecuta dentro de una transacción con
     * el tenant ya fijado: quien llama es el responsable de eso.
     */
    private function generar(?Carbon $hoy = null): ResultadoGeneracionAlertas
    {
        $hoy = ($hoy ?? Carbon::now())->copy()->startOfDay();

        $resultado = new ResultadoGeneracionAlertas;

        Documento::query()
            ->where('estado', 'vigente')
            ->with(['tipoDocumento', 'vehiculo', 'conductor', 'reglaFrecuencia'])
            ->orderBy('id')
            ->each(function (Documento $documento) use ($hoy, $resultado): void {
                $resultado->documentosRevisados++;

                $this->evaluarDocumento($documento, $hoy, $resultado);
            });

        return $resultado;
    }

    private function evaluarDocumento(
        Documento $documento,
        Carbon $hoy,
        ResultadoGeneracionAlertas $resultado,
    ): void {
        $efectiva = $documento->fechaVencimientoEfectiva();

        // Sin fecha efectiva no hay nada que avisar. Se cuenta aparte para que
        // la pantalla pueda mostrar "3 documentos sin fecha de vencimiento",
        // que es un dato de calidad de la carga, no un error del sistema.
        if ($efectiva['fecha'] === null) {
            $resultado->documentosSinFecha++;

            return;
        }

        $diasRestantes = (int) $hoy->diffInDays($efectiva['fecha']->copy()->startOfDay(), false);

        if ($diasRestantes < 0) {
            $resultado->documentosVencidos++;
        }

        $configuraciones = ConfiguracionAlerta::umbralesPara($documento->tipo_documento_id);

        foreach ($configuraciones as $configuracion) {
            $this->evaluarUmbral($documento, $configuracion, $efectiva, $diasRestantes, $resultado);
        }
    }

    private function evaluarUmbral(
        Documento $documento,
        ConfiguracionAlerta $configuracion,
        array $efectiva,
        int $diasRestantes,
        ResultadoGeneracionAlertas $resultado,
    ): void {
        // CASO 1: dentro de la ventana de anticipación.
        //
        // 0 <= días restantes <= umbral. El día del vencimiento también
        // entra: un documento que vence HOY ya es un problema que alguien
        // tiene que resolver hoy.
        if ($diasRestantes >= 0 && $diasRestantes <= (int) $configuracion->umbral_dias) {
            $this->registrar(
                documento: $documento,
                tipo: 'umbral',
                umbralDias: (int) $configuracion->umbral_dias,
                fecha: $efectiva['fecha'],
                origen: $efectiva['origen'],
                diasRestantes: $diasRestantes,
                configuracion: $configuracion,
                resultado: $resultado,
            );

            return;
        }

        // CASO 2: ya vencido.
        //
        // Se genera UNA alerta de vencimiento por ciclo, y opcionalmente
        // recordatorios si la flota los configuró. Cada uno con su propia
        // clave de periodo, así que no se pisan entre sí.
        if ($diasRestantes < 0) {
            $diasVencido = abs($diasRestantes);

            $this->registrar(
                documento: $documento,
                tipo: 'vencido',
                umbralDias: null,
                fecha: $efectiva['fecha'],
                origen: $efectiva['origen'],
                diasRestantes: $diasRestantes,
                configuracion: $configuracion,
                resultado: $resultado,
            );

            // Recordatorio posterior, si la flota lo pidió. Por defecto son
            // cero días: un aviso de vencimiento y nada más, hasta que la
            // flota diga que quiere más insistencia.
            $insistencia = (int) $configuracion->dias_insistencia_vencido;

            if ($insistencia > 0 && $diasVencido >= $insistencia) {
                $this->registrar(
                    documento: $documento,
                    tipo: 'insistencia',
                    umbralDias: null,
                    fecha: $efectiva['fecha'],
                    origen: $efectiva['origen'],
                    diasRestantes: $diasRestantes,
                    configuracion: $configuracion,
                    resultado: $resultado,
                    insistenciaDias: $insistencia,
                );
            }
        }
    }

    /**
     * Inserta la alerta si el periodo no existe todavia, y despacha el envío.
     *
     * La inserción es idempotente por construcción. `insertOrIgnore` devuelve
     * cuantas filas insertó de verdad: 0 significa que ya existía el aviso de
     * ese periodo, y eso NO es un error, es el comportamiento correcto de una
     * tarea programada que se reejecuta.
     */
    private function registrar(
        Documento $documento,
        string $tipo,
        ?int $umbralDias,
        Carbon $fecha,
        string $origen,
        int $diasRestantes,
        ConfiguracionAlerta $configuracion,
        ResultadoGeneracionAlertas $resultado,
        ?int $insistenciaDias = null,
    ): void {
        $periodoClave = AlertaDocumento::calcularPeriodoClave($fecha, $tipo, $umbralDias, $insistenciaDias);

        $alertaId = Str::uuid()->toString();

        $insertadas = DB::table('alertas_documento')->insertOrIgnore([
            'id' => $alertaId,
            'tenant_id' => $documento->tenant_id,
            'documento_id' => $documento->id,
            'vehiculo_id' => $documento->vehiculo_id,
            'conductor_id' => $documento->conductor_id,
            'tipo_documento_id' => $documento->tipo_documento_id,
            'fecha_vencimiento_referencia' => $fecha->format('Y-m-d'),
            'origen_vencimiento' => $origen,
            'tipo' => $tipo,
            'umbral_dias' => $umbralDias,
            'dias_restantes' => $diasRestantes,
            'periodo_clave' => $periodoClave,
            'estado' => EstadoAlerta::Pendiente->value,
            'canal' => $configuracion->canal,
            'destinatarios' => $configuracion->destinatarios,
            'origen_generacion' => 'flota:generar-alertas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($insertadas === 0) {
            // El aviso de este periodo ya existía. No se duplica y no se
            // vuelve a enviar.
            $resultado->alertasOmitidasPorDuplicado++;

            return;
        }

        $resultado->alertasGeneradas++;

        if (config('flota.envio_activo', false) === true) {
            EnviarAlertaVencimiento::dispatch($alertaId, $documento->tenant_id);
            $resultado->alertasDespachadas++;
        }
    }
}