<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Modelo `Viaje`: una salida con vehiculo y conductor. */
class Viaje extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'viajes';

    protected $fillable = [
        'vehiculo_id',
        'conductor_id',
        'asignacion_id',
        'origen',
        'destino',
        'proposito',
        'estado',
        'fecha_salida',
        'fecha_retorno',
        'km_salida',
        'km_retorno',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_salida' => 'datetime',
            'fecha_retorno' => 'datetime',
            'km_salida' => 'integer',
            'km_retorno' => 'integer',
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

    public function asignacion()
    {
        return $this->belongsTo(Asignacion::class);
    }

    /** Kilometraje recorrido en el viaje, si ya volvio. */
    public function distanciaRecorrida(): ?int
    {
        if ($this->km_salida === null || $this->km_retorno === null) {
            return null;
        }

        return $this->km_retorno - $this->km_salida;
    }

    public function destinoCompleto(): string
    {
        return trim(($this->origen ?? '').' -> '.($this->destino ?? ''), ' ->');
    }
}