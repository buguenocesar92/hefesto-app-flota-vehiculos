<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol del usuario dentro de SU flota.
 *
 * Un usuario no tiene rol global: es administrador de su flota y nada fuera
 * de ella. La RLS ya garantiza que no alcance datos ajenos; estos roles
 * ordenan lo que puede hacer DENTRO de los suyos.
 *
 * Ver la tabla completa en docs/03-MULTITENANCY-Y-ROLES.md.
 */
enum RolUsuario: string
{
    /** Ve y escribe todo dentro de su flota, incluida la configuracion. */
    case Administrador = 'administrador';

    /** Opera la flota. No cambia la configuracion de alertas ni de catalogos. */
    case Operador = 'operador';

    /** Solo lee. Es el rol por defecto y el que se usa para mostrar el tablero. */
    case Consulta = 'consulta';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Administrador => 'Administrador',
            self::Operador => 'Operador',
            self::Consulta => 'Consulta',
        };
    }

    public function puedeEscribir(): bool
    {
        return $this !== self::Consulta;
    }

    /** Cambiar configuracion es una accion de administracion, no de operacion. */
    public function puedeConfigurar(): bool
    {
        return $this === self::Administrador;
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }
}