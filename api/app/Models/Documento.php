<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoTemporalDocumento;
use App\Models\Concerns\PerteneceATenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo `Documento`: el permiso de circulacion, el seguro, la revision
 * tecnica, el padron de fiscalizacion, la licencia del conductor.
 *
 * ESTE MODELO ES DONDE VIVE LA SEPARACION ENTRE LOS DOS TIPOS DE
 * VENCIMIENTO, que es el detalle de dominio que mas se pidio explicitar:
 *
 *   - `fechaVencimientoImpresa()`: la fecha que viene escrita en el
 *     documento. Es un hecho. El sistema no la calcula ni la corrige.
 *
 *   - `fechaVencimientoCalculada()`: la fecha que sale de una regla de
 *     frecuencia configurada (inspeccion cada N dias o cada N
 *     kilometros). Es una ESTIMACION de trabajo.
 *
 *   - `fechaVencimientoEfectiva()`: la que usa el generador de alertas, y
 *     dice de donde salio. Si hay fecha impresa, manda la impresa. Si no hay,
 *     se usa la calculada, y el aviso sale marcado como estimado.
 *
 * Nunca se mezclan en el mismo campo ni se suman. Un documento con las dos
 * fechas muestra las dos, con su origen.
 */
class Documento extends Model
{
    use HasUuids;
    use PerteneceATenant;
    use SoftDeletes;

    protected $table = 'documentos';

    protected $fillable = [
        'vehiculo_id',
        'conductor_id',
        'tipo_documento_id',
        'numero',
        'emisora',
        'organo_emisor',
        'fecha_emision',
        'fecha_vencimiento',
        'estado',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'fecha_vencimiento' => 'date',
            'estado' => 'string',
        ];
    }

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function conductor()
    {
        return $this->belongsTo(Conductor::class);
    }

    public function tipoDocumento()
    {
        return $this->belongsTo(TipoDocumento::class);
    }

    public function reglaFrecuencia()
    {
        return $this->hasOne(ReglaFrecuenciaDocumento::class);
    }

    public function alertas()
    {
        return $this->hasMany(AlertaDocumento::class);
    }

    /**
     * Fecha de vencimiento por INSPECCION, calculada.
     *
     * Devuelve null cuando no hay regla activa, o cuando la periodicidad no
     * esta confirmada por nadie. Una periodicidad sin `confirmado_por` es un
     * numero que alguien escribio de memoria: el sistema lo considera no
     * confiable y no lo usa para avisar.
     */
    public function fechaVencimientoCalculada(): ?Carbon
    {
        $regla = $this->reglaFrecuencia;

        if ($regla === null || ! $regla->activa || ! $regla->estaConfirmada()) {
            return null;
        }

        if ($regla->periodicidad_dias === null || $regla->ultima_inspeccion_fecha === null) {
            return null;
        }

        return Carbon::parse($regla->ultima_inspeccion_fecha)
            ->addDays((int) $regla->periodicidad_dias);
    }

    /**
     * Fecha que usa el generador de alertas, y de donde sale.
     *
     * @return array{fecha: ?Carbon, origen: string}
     */
    public function fechaVencimientoEfectiva(): array
    {
        if ($this->fecha_vencimiento !== null) {
            return [
                'fecha' => Carbon::instance($this->fecha_vencimiento),
                'origen' => 'documento',
            ];
        }

        $calculada = $this->fechaVencimientoCalculada();

        if ($calculada !== null) {
            return [
                'fecha' => $calculada,
                'origen' => 'regla_frecuencia',
            ];
        }

        return ['fecha' => null, 'origen' => 'ninguno'];
    }

    /**
     * Dias que faltan. Negativo cuando ya vencio.
     *
     * El calculo es en dias calendario completos, con la zona horaria de la
     * flota: un documento que vence el 20 tiene 0 dias restantes el dia 20,
     * no -1. Esa diferencia de un dia es la que separa "vencido" de "vence
     * hoy", y en un sistema de alertas esa frase se manda al chofer.
     */
    public function diasRestantes(?Carbon $hoy = null): ?int
    {
        $efectiva = $this->fechaVencimientoEfectiva();

        if ($efectiva['fecha'] === null) {
            return null;
        }

        $hoy = ($hoy ?? Carbon::now())->copy()->startOfDay();
        $vencimiento = $efectiva['fecha']->copy()->startOfDay();

        return (int) $hoy->diffInDays($vencimiento, false);
    }

    /** Estado temporal calculado, nunca guardado. */
    public function estadoTemporal(int $diasDeAnticipacion = 30): EstadoTemporalDocumento
    {
        return EstadoTemporalDocumento::calcular(
            $this->fechaVencimientoEfectiva()['fecha'],
            $diasDeAnticipacion,
            $this->estado
        );
    }

    public function estaVencido(): bool
    {
        return $this->estadoTemporal() === EstadoTemporalDocumento::Vencido;
    }

    /**
     * Descripcion del sujeto: "Vehiculo ABCD12" o "Conductor Juan Perez".
     * Se usa en el asunto del aviso para que el operador sepa de un vistazo a
     * quien se le esta avisando.
     */
    public function etiquetaDelSujeto(): string
    {
        if ($this->vehiculo !== null) {
            return 'Vehiculo '.$this->vehiculo->patente;
        }

        if ($this->conductor !== null) {
            return 'Conductor '.$this->conductor->nombreCompleto();
        }

        return 'Sujeto sin identificar';
    }
}