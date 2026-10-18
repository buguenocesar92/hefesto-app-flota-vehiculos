<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo `Conductor`.
 *
 * Sus documentos (la licencia, por ejemplo) viven en la misma tabla
 * `documentos` con `conductor_id` informado. Ver
 * `App\Models\Documento`.
 */
class Conductor extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'conductores';

    protected $fillable = [
        'rut',
        'nombre',
        'apellido_paterno',
        'apellido_materno',
        'clase_licencia',
        'telefono',
        'correo',
        'usuario_id',
        'fecha_ingreso',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'fecha_ingreso' => 'date',
        ];
    }

    public function documentos()
    {
        return $this->hasMany(Documento::class);
    }

    public function asignaciones()
    {
        return $this->hasMany(Asignacion::class);
    }

    public function viajes()
    {
        return $this->hasMany(Viaje::class);
    }

    public function lecturas()
    {
        return $this->hasMany(LecturaKilometraje::class);
    }

    public function nombreCompleto(): string
    {
        return trim(implode(' ', array_filter([
            $this->nombre,
            $this->apellido_paterno,
            $this->apellido_materno,
        ])));
    }
}