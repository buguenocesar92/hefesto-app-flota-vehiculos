<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceATenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo `ReglaFrecuenciaDocumento`: el vencimiento POR INSPECCION.
 *
 * Companion de `Documento`, con el que se separa del vencimiento por fecha.
 * Ver el comentario de `App\Models\Documento`.
 */
class ReglaFrecuenciaDocumento extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'reglas_frecuencia_documento';

    protected $fillable = [
        'documento_id',
        'tipo_documento_id',
        'periodicidad_dias',
        'periodicidad_km',
        'ultima_inspeccion_fecha',
        'ultima_inspeccion_km',
        'confirmado_por',
        'fuente_plazo',
        'confirmado_at',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'periodicidad_dias' => 'integer',
            'periodicidad_km' => 'integer',
            'ultima_inspeccion_fecha' => 'date',
            'ultima_inspeccion_km' => 'integer',
            'confirmado_at' => 'immutable_datetime',
            'activa' => 'boolean',
        ];
    }

    public function documento()
    {
        return $this->belongsTo(Documento::class);
    }

    public function tipoDocumento()
    {
        return $this->belongsTo(TipoDocumento::class);
    }

    /**
     * Una periodicidad solo se usa para avisar si alguien se hizo cargo de
     * confirmarla.
     *
     * Este es el mecanismo que impide que el sistema invente plazos legales.
     * Se puede cargar una periodicidad de borrador mientras se conversa con el
     * cliente, pero no entra en el calculo de alertas hasta que quede
     * registrado quien la confirmo y con que fuente.
     */
    public function estaConfirmada(): bool
    {
        return $this->confirmado_por !== null
            && $this->fuente_plazo !== null
            && $this->confirmado_at !== null;
    }

    /**
     * Proxima fecha segun la periodicidad en dias.
     */
    public function proximaPorDias(): ?Carbon
    {
        if ($this->periodicidad_dias === null || $this->ultima_inspeccion_fecha === null) {
            return null;
        }

        return Carbon::parse($this->ultima_inspeccion_fecha)
            ->addDays((int) $this->periodicidad_dias);
    }

    /**
     * Kilometraje en el que toca la proxima inspeccion.
     */
    public function proximaPorKilometros(): ?int
    {
        if ($this->periodicidad_km === null || $this->ultima_inspeccion_km === null) {
            return null;
        }

        return (int) $this->ultima_inspeccion_km + (int) $this->periodicidad_km;
    }
}