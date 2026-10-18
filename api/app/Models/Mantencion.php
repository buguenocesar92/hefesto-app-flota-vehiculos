<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo `Mantencion`.
 *
 * Supporting del modulo central. La mantencion agenda y ordena; el
 * vencimiento de documentos avisa y evita que el negocio se detenga de
 * golpe.
 */
class Mantencion extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'mantenciones';

    protected $fillable = [
        'vehiculo_id',
        'taller_id',
        'estado',
        'tipo',
        'descripcion',
        'km_programado',
        'fecha_programada',
        'fecha_ejecucion',
        'km_al_entregar',
        'costo',
        'km_proximo',
        'fecha_proxima',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'km_programado' => 'integer',
            'km_al_entregar' => 'integer',
            'km_proximo' => 'integer',
            'costo' => 'integer',
            'fecha_programada' => 'date',
            'fecha_ejecucion' => 'date',
            'fecha_proxima' => 'date',
        ];
    }

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function taller()
    {
        return $this->belongsTo(Taller::class);
    }

    public function lecturas()
    {
        return $this->hasMany(LecturaKilometraje::class);
    }

    public function estaEnProceso(): bool
    {
        return $this->estado === 'en_proceso';
    }
}