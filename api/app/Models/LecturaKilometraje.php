<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FuenteKilometraje;
use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo `LecturaKilometraje`: una lectura CON SU ORIGEN.
 *
 * Ninguna lectura existe sin saber de donde salio. El campo `fuente` no es
 * decorativo: es lo que permite responder "este numero de donde vino", y lo
 * que hace que una correccion se vea como correccion y no como un dato mas.
 */
class LecturaKilometraje extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'lecturas_kilometraje';

    protected $fillable = [
        'vehiculo_id',
        'km',
        'fuente',
        'fecha',
        'lectura_anterior_id',
        'mantencion_id',
        'viaje_id',
        'carga_combustible_id',
        'conductor_id',
        'usuario_id',
        'motivo_ajuste',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'km' => 'integer',
            'fuente' => FuenteKilometraje::class,
            'fecha' => 'date',
        ];
    }

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function lecturaAnterior()
    {
        return $this->belongsTo(self::class, 'lectura_anterior_id');
    }

    public function lecturaSiguiente()
    {
        return $this->hasOne(self::class, 'lectura_anterior_id');
    }

    public function conductor()
    {
        return $this->belongsTo(Conductor::class);
    }

    public function mantencion()
    {
        return $this->belongsTo(Mantencion::class);
    }

    public function viaje()
    {
        return $this->belongsTo(Viaje::class);
    }

    public function cargaCombustible()
    {
        return $this->belongsTo(CargaCombustible::class);
    }

    /** Kilometraje recorrido desde la lectura anterior. */
    public function tramosRecorridos(): ?int
    {
        if ($this->lectura_anterior_id === null || $this->lecturaAnterior === null) {
            return null;
        }

        return $this->km - (int) $this->lecturaAnterior->km;
    }
}