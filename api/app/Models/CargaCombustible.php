<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo `CargaCombustible`.
 *
 * NOTA: no tiene columna de rendimiento. El rendimiento se calcula sobre las
 * cargas consecutivas (ver `App\Services\Combustible\CalculadoraDeRendimiento`).
 * Guardarlo seria volver a crear el problema que el sistema vino a
 * eliminar: un numero que depende de que alguien lo escribiera bien.
 */
class CargaCombustible extends Model
{
    use HasUuids;
    use PerteneceATenant;

    protected $table = 'cargas_combustible';

    protected $fillable = [
        'vehiculo_id',
        'conductor_id',
        'fecha',
        'litros',
        'precio_unitario',
        'total',
        'tipo_combustible',
        'estacion',
        'km_lectura',
        'numero_boleta',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'litros' => 'decimal:2',
            'precio_unitario' => 'decimal:2',
            'total' => 'integer',
            'km_lectura' => 'integer',
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

    public function lectura()
    {
        return $this->hasOne(LecturaKilometraje::class);
    }

    /**
     * Total en pesos. Se calcula y no se confía en lo que venga guardado,
     * porque el total tambien se puede cargar desde el formulario.
     */
    public function totalCalculado(): float
    {
        return round((float) $this->litros * (float) $this->precio_unitario);
    }
}