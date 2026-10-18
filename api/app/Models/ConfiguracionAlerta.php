<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo `ConfiguracionAlerta`: los PLAZOS DE AVISO de la flota.
 *
 * Lo configurable por el cliente, y unico lugar donde vive:
 * cuantos dias antes del vencimiento quiere que le avisen.
 *
 * OJO CON LA DISTINCION, porque es la que mas confunde:
 *
 *   - ESTE numero es un plazo de AVISO INTERNO. "Con 30 dias" significa
 *     "avisame un mes antes", no "la ley dice que dura 30 dias".
 *
 *   - El plazo legal de cada documento vive en `tipos_documento`, con su
 *     fuente y quien lo confirmo, y no se usa para calcular nada mientras no
 *     este confirmado.
 *
 * `tipo_documento_id` en NULL significa que el umbral aplica a todos los
 * tipos de documento de la flota.
 */
class ConfiguracionAlerta extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'configuraciones_alerta';

    protected $fillable = [
        'tipo_documento_id',
        'umbral_dias',
        'dias_insistencia_vencido',
        'canal',
        'destinatarios',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'umbral_dias' => 'integer',
            'dias_insistencia_vencido' => 'integer',
            'destinatarios' => 'array',
            'activa' => 'boolean',
        ];
    }

    public function tipoDocumento()
    {
        return $this->belongsTo(TipoDocumento::class);
    }

    /** El umbral aplica a todos los tipos. */
    public function esGlobal(): bool
    {
        return $this->tipo_documento_id === null;
    }

    /**
     * Lista de umbrales para un tipo de documento: los del tipo mas los
     * globales.
     *
     * Se combinan porque un cliente puede querer "avisame 30 dias antes de
     * todo" y ademas "avisame 7 dias antes del permiso de circulacion". El
     * mas cercano de los dos gana.
     *
     * @return list<self>
     */
    public static function umbralesPara(?string $tipoDocumentoId): array
    {
        $consulta = self::query()
            ->where('activa', true)
            ->where(function ($q) use ($tipoDocumentoId): void {
                $q->whereNull('tipo_documento_id');

                if ($tipoDocumentoId !== null) {
                    $q->orWhere('tipo_documento_id', $tipoDocumentoId);
                }
            });

        return $consulta->orderBy('umbral_dias')
            ->get()
            ->all();
    }
}