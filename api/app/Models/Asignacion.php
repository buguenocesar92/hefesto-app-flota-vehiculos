<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo `Asignacion`: que vehiculo esta con que conductor, desde cuando.
 *
 * `hasta` en NULL significa abierta. Los indices unicos parciales de la base
 * garantizan que no haya dos asignaciones abiertas para el mismo vehiculo ni
 * para el mismo conductor.
 */
class Asignacion extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'asignaciones';

    protected $fillable = [
        'vehiculo_id',
        'conductor_id',
        'desde',
        'hasta',
        'motivo',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'desde' => 'date',
            'hasta' => 'date',
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

    public function viajes()
    {
        return $this->hasMany(Viaje::class);
    }

    public function estaAbierta(): bool
    {
        return $this->hasta === null;
    }

    /** Conductores que estarian disponibles: los que no tienen asignacion abierta. */
    public function scopeLibres($consulta)
    {
        return $consulta->whereDoesntHave('asignaciones', function ($q): void {
            $q->whereNull('hasta');
        });
    }
}