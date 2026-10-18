<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo `Tenant`: una flota.
 *
 * Es la unica entidad que NO usa el trait `PerteneceATenant`: no pertenece a
 * un tenant, ES el tenant. Tampoco lleva RLS, porque se consulta antes del
 * login. Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
class Tenant extends Model
{
    use HasUuids;

    protected $table = 'tenants';

    protected $fillable = [
        'slug',
        'nombre',
        'razon_social',
        'rut',
        'correo_contacto',
        'telefono',
        'activo',
        'zona_horaria',
    ];

    /**
     * El RUT chileno no se expone en las respuestas de la API por defecto:
     * es dato identificatorio y no hace falta para operar la flota.
     */
    protected $hidden = ['rut'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'alertas_generadas_at' => 'immutable_datetime',
        ];
    }

    public function vehiculos()
    {
        return $this->hasMany(Vehiculo::class);
    }

    public function conductores()
    {
        return $this->hasMany(Conductor::class);
    }

    public function usuarios()
    {
        return $this->hasMany(Usuario::class);
    }

    /**
     * Zona horaria con la que se calculan los dias que faltan para un
     * vencimiento. Es por tenant y no global porque una flota puede operar
     * en otra region del pais.
     */
    public function zonaHorariaDeCalculo(): string
    {
        return $this->zona_horaria ?: config('flota.zona_horaria', 'America/Santiago');
    }
}