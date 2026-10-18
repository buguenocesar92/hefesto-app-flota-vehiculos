<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\Carbon;

/**
 * Estado temporal de un documento.
 *
 * ESTE ESTADO NO SE GUARDA EN LA BASE. Se calcula desde la fecha.
 *
 * Es la decision de diseño mas importante del modulo de documentos, y vale la
 * pena explicar el porque. Guardar "vencido" en una columna parece lo mas
 * simple, pero crea una segunda fuente de verdad. Basta con que alguien
 * corrija la fecha de vencimiento de un documento desde el formulario de
 * edicion (una operacion legitima) y el estado guardado se quede diciendo
 * "vencido": el permiso de circulacion aparece caducado en la pantalla
 * siendo valido, o al reves, que es el caso que mata.
 *
 * Con el estado calculado, la unica fuente de verdad es la fecha, y no hay
 * forma de que las dos se desincronicen porque son la misma cosa.
 */
enum EstadoTemporalDocumento: string
{
    /** Vigente y con margen. */
    case Vigente = 'vigente';

    /**
     * Vigente, pero dentro de la ventana de anticipacion configurada. Se
     * determina por el umbral mas alto que tenga activa la flota.
     */
    case PorVencer = 'por_vencer';

    /** La fecha de vencimiento ya paso. */
    case Vencido = 'vencido';

    /** El documento no tiene fecha de vencimiento, y tampoco regla de frecuencia. */
    case SinFecha = 'sin_fecha';

    /**
     * Anulado o reemplazado: ya no es el documento vigente del vehiculo, y
     * se conserva solo como historial.
     */
    case Retirado = 'retirado';

    /** Etiqueta para mostrar en pantalla. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Vigente => 'Vigente',
            self::PorVencer => 'Por vencer',
            self::Vencido => 'Vencido',
            self::SinFecha => 'Sin fecha de vencimiento',
            self::Retirado => 'Retirado',
        };
    }

    /**
     * Estado calculado a partir de las fechas.
     *
     * @param  \DateTimeInterface|null $fechaVencimiento  fecha que el sistema usa para avisar (impresa o calculada)
     * @param  int  $diasDeAnticipacion  mayor umbral de aviso activo en la flota
     */
    public static function calcular(
        ?\DateTimeInterface $fechaVencimiento,
        int $diasDeAnticipacion = 30,
        string $estadoAdministrativo = 'vigente',
    ): self {
        if ($estadoAdministrativo !== 'vigente') {
            return self::Retirado;
        }

        if ($fechaVencimiento === null) {
            return self::SinFecha;
        }

        $hoy = now()->startOfDay();
        $vencimiento = Carbon::instance($fechaVencimiento)->startOfDay();

        // Negativo cuando la fecha ya paso.
        $dias = (int) $hoy->diffInDays($vencimiento, false);

        if ($dias < 0) {
            return self::Vencido;
        }

        if ($dias <= $diasDeAnticipacion) {
            return self::PorVencer;
        }

        return self::Vigente;
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }
}