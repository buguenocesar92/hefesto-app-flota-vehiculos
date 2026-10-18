<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo `TipoDocumento`: el catalogo nacional.
 *
 * NO usa `PerteneceATenant` a proposito: es la unica tabla de negocio que no
 * lleva `tenant_id`, porque describe los tipos de documento que la normativa
 * chilena contempla, no los documentos de una flota. Ver el comentario de su
 * migracion para el analisis completo.
 *
 * Sobre los plazos legales: las columnas `plazo_legal_*` arrancan vacias y el
 * sistema NO las usa para calcular vencimientos. Hefesto debe confirmarlas con
 * un profesional del transporte o con el cliente. Ver docs/02.
 */
class TipoDocumento extends Model
{
    use HasUuids;

    protected $table = 'tipos_documento';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'sujeto',
        'requiere_vencimiento',
        'organo_emisor',
        'plazo_legal_dias',
        'plazo_legal_fuente',
        'plazo_legal_confirmado_por',
        'plazo_legal_confirmado_at',
        'nota',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'requiere_vencimiento' => 'boolean',
            'activo' => 'boolean',
            'plazo_legal_dias' => 'integer',
            'plazo_legal_confirmado_at' => 'immutable_datetime',
        ];
    }

    public function documentos()
    {
        return $this->hasMany(Documento::class);
    }

    public function esDeConductor(): bool
    {
        return $this->sujeto === 'conductor';
    }

    /**
     * El plazo legal estalla confirmado y documentado.
     *
     * Nadie debe tratar un plazo sin confirmar como un plazo. El panel lo
     * muestra como "pendiente de confirmacion" y no lo usa para nada.
     */
    public function tienePlazoLegalConfirmado(): bool
    {
        return $this->plazo_legal_dias !== null
            && $this->plazo_legal_fuente !== null
            && $this->plazo_legal_confirmado_por !== null;
    }
}