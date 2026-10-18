<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoOperativoVehiculo;
use App\Models\Concerns\PerteneceATenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo `Vehiculo`.
 *
 * Dos cosas que este modelo NO tiene, y que son decisiones:
 *
 * 1. NO tiene `kilometraje_actual`. El kilometraje se deriva de
 *    `lecturas_kilometraje` (ver `App\Services\Kilometraje\KilometrajeActual`).
 *    Un numero guardado seria una segunda fuente de verdad, y la diferencia
 *    entre ambas seria invisible.
 *
 * 2. NO tiene `estado`. Ni el documental ni el operativo: los dos se
 *    calculan, porque un estado guardado se queda viejo el dia que el
 *    documento vence o el vehiculo se mantiene en el taller, y nadie va a
 *    recordarse de actualizarlo. Ver
 *    `App\Services\Vehiculos\EstadoOperativoVehiculo`.
 */
class Vehiculo extends Model
{
    use HasUuids;
    use PerteneceATenant;
    use SoftDeletes;

    protected $table = 'vehiculos';

    protected $fillable = [
        'numero_interno',
        'patente',
        'vin',
        'marca',
        'modelo',
        'anio',
        'color',
        'chasis',
        'tipo_servicio_id',
        'centro_costo_id',
        'kilometraje_referencia',
        'activo',
        'retirado_at',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'kilometraje_referencia' => 'integer',
            'activo' => 'boolean',
            'retirado_at' => 'date',
        ];
    }

    public function tipoServicio()
    {
        return $this->belongsTo(TipoServicio::class);
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class);
    }

    public function documentos()
    {
        return $this->hasMany(Documento::class);
    }

    /** Documentos que son de este vehiculo y siguen vigentes. */
    public function documentosVigentes()
    {
        return $this->documentos()->where('estado', 'vigente');
    }

    public function mantenciones()
    {
        return $this->hasMany(Mantencion::class);
    }

    public function lecturas()
    {
        return $this->hasMany(LecturaKilometraje::class);
    }

    public function cargasCombustible()
    {
        return $this->hasMany(CargaCombustible::class);
    }

    public function viajes()
    {
        return $this->hasMany(Viaje::class);
    }

    public function asignaciones()
    {
        return $this->hasMany(Asignacion::class);
    }

    /** La asignacion abierta, si la hay. */
    public function asignacionAbierta()
    {
        return $this->hasOne(Asignacion::class)->whereNull('hasta');
    }

    public function alertas()
    {
        return $this->hasMany(AlertaDocumento::class);
    }

    /**
     * Estado OPERATIVO calculado: si esta en el taller, asignado o
     * disponible.
     *
     * Deliberadamente NO incluye el estado documental. Un vehiculo puede
     * estar en mantension Y con el seguro vencido: son dos ejes y se
     * presentan juntos. Ver `App\Services\Vehiculos\EstadoOperativoVehiculo`.
     */
    public function estadoOperativo(): EstadoOperativoVehiculo
    {
        if ($this->activo === false || $this->retirado_at !== null) {
            return EstadoOperativoVehiculo::Retirado;
        }

        if ($this->mantenciones()->where('estado', 'en_proceso')->exists()) {
            return EstadoOperativoVehiculo::EnMantencion;
        }

        if ($this->asignacionAbierta()->exists()) {
            return EstadoOperativoVehiculo::Asignado;
        }

        return EstadoOperativoVehiculo::Disponible;
    }

    /** Kilometraje de la ultima lectura registrada, o la referencia inicial. */
    public function kilometrajeActual(): ?int
    {
        $ultima = $this->lecturas()->orderByDesc('km')->first();

        return $ultima?->km ?? $this->kilometraje_referencia;
    }

    /** Identificacion para pantalla: la patente es lo que el operador usa. */
    public function identificacion(): string
    {
        $descripcion = trim($this->marca.' '.$this->modelo);

        return $this->numero_interno !== null
            ? sprintf('%s (%s)', $this->patente, $descripcion)
            : $this->patente;
    }
}