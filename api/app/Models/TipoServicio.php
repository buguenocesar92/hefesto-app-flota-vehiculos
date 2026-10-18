<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Modelo `TipoServicio`. */
class TipoServicio extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'tipos_servicio';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'requiere_habilitacion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'requiere_habilitacion' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function vehiculos()
    {
        return $this->hasMany(Vehiculo::class);
    }
}