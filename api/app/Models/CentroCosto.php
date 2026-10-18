<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Modelo `CentroCosto`: donde se imputa el gasto del vehiculo. */
class CentroCosto extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'centros_costo';

    protected $fillable = [
        'centro_costo_padre_id',
        'codigo',
        'nombre',
        'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function vehiculos()
    {
        return $this->hasMany(Vehiculo::class);
    }

    public function padre()
    {
        return $this->belongsTo(self::class, 'centro_costo_padre_id');
    }

    public function hijos()
    {
        return $this->hasMany(self::class, 'centro_costo_padre_id');
    }

    /** Ruta jerarquica completa, para mostrar "Region Norte / Sector A". */
    public function rutaCompleta(): string
    {
        $partes = [$this->nombre];
        $actual = $this->padre;

        while ($actual !== null) {
            array_unshift($partes, $actual->nombre);
            $actual = $actual->padre;
        }

        return implode(' / ', $partes);
    }
}