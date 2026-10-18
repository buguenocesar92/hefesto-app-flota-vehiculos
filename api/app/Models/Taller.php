<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Modelo `Taller`: quien ejecuta la mantencion. */
class Taller extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'talleres';

    protected $fillable = [
        'nombre',
        'rut',
        'contacto',
        'telefono',
        'especialidad',
        'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function mantenciones()
    {
        return $this->hasMany(Mantencion::class);
    }
}