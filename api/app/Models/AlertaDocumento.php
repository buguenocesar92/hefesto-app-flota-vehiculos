<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoAlerta;
use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo `AlertaDocumento`: el aviso ya generado.
 *
 * `periodo_clave` es lo que hace que el aviso no se repita. Ver el comentario
 * de la migracion `2026_03_01_000013`: el indice unico
 * (documento_id, periodo_clave) es la garantia real, no el codigo del
 * generador.
 *
 * Los dias que faltan se guardan como foto del momento en que se genero el
 * aviso. Un aviso de hace un mes sigue diciendo "vencia en 7 dias", que es
 * exactamente lo que el operador necesita ver, en vez de recalcular una cifra
 * que ya no significa nada.
 */
class AlertaDocumento extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'alertas_documento';

    protected $fillable = [
        'documento_id',
        'vehiculo_id',
        'conductor_id',
        'tipo_documento_id',
        'fecha_vencimiento_referencia',
        'origen_vencimiento',
        'tipo',
        'umbral_dias',
        'dias_restantes',
        'periodo_clave',
        'estado',
        'canal',
        'destinatarios',
        'enviada_at',
        'error_texto',
        'resuelta_at',
        'origen_generacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vencimiento_referencia' => 'date',
            'umbral_dias' => 'integer',
            'dias_restantes' => 'integer',
            'estado' => EstadoAlerta::class,
            'destinatarios' => 'array',
            'enviada_at' => 'immutable_datetime',
            'resuelta_at' => 'immutable_datetime',
        ];
    }

    public function documento()
    {
        return $this->belongsTo(Documento::class);
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

    /**
     * La alerta se genero a partir de una periodicidad configurada y no de
     * una fecha impresa en el documento. Se marca en la interfaz.
     */
    public function esEstimada(): bool
    {
        return $this->origen_vencimiento === 'regla_frecuencia';
    }

    public function estaPendiente(): bool
    {
        return $this->estado === EstadoAlerta::Pendiente;
    }

    /**
     * Construye la clave de periodo de forma determinista.
     *
     * Determinista es la palabra clave: la misma fecha de vencimiento y el
     * mismo tipo de aviso producen SIEMPRE la misma cadena. Por eso el
     * generador puede reejecutarse sin miedo a duplicar.
     *
     * Formato: `v:{fecha}:{tipo}:{detalle}`.
     *
     * El detalle distingue el aviso de 30 días del de 15 (`umbral:30`,
     * `umbral:15`) y los recordatorios de insistencia entre sí
     * (`insistencia:7`, `insistencia:14`). Si una flota sube la insistencia
     * de 7 a 14 días, el aviso nuevo es un periodo nuevo y sale; el antiguo
     * no lo bloquea.
     */
    public static function calcularPeriodoClave(
        \DateTimeInterface $fechaVencimiento,
        string $tipo,
        ?int $umbralDias = null,
        ?int $insistenciaDias = null,
    ): string {
        $detalle = match ($tipo) {
            'umbral' => 'umbral:'.(int) $umbralDias,
            'insistencia' => 'insistencia:'.(int) $insistenciaDias,
            default => $tipo,
        };

        return 'v:'.$fechaVencimiento->format('Y-m-d').':'.$detalle;
    }

    /** Titulo corto para la bandeja de alertas. */
    public function resumen(): string
    {
        $sujeto = $this->vehiculo?->patente
            ?? $this->conductor?->nombreCompleto()
            ?? 'sin sujeto';

        $tipoDocumento = $this->tipoDocumento?->nombre ?? 'documento';

        if ($this->estado === EstadoAlerta::Vencido || $this->tipo === 'vencido') {
            return sprintf('%s vencido en %s', $tipoDocumento, $sujeto);
        }

        return sprintf(
            '%s de %s vence en %d dias',
            $tipoDocumento,
            $sujeto,
            max(0, (int) $this->dias_restantes)
        );
    }
}