<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Normalización de patentes chilenas.
 *
 * El detalle que parece tonto y no lo es: la patente se escribe "AB CD 12",
 * "AB·CD-12", "abcd12" y "AB-CD-12" según quién la escriba y desde qué
 * teclado. Si se guarda tal cual, la misma camioneta aparece cuatro veces en
 * el listado, y la alerta de vencimiento se manda cuatro veces.
 *
 * Aquí se decide UNA forma canónica y se guarda siempre esa:
 *   - sin espacios, sin puntos, sin comillas
 *   - dos letras, guion, y el resto de letras o dígitos
 *   - mayúsculas
 *
 * Ejemplos:
 *   "ab cd12"  -> "ABCD12"
 *   "AB·CD-12" -> "ABCD-12"
 *   "bb bb 10" -> "BBBB-10"
 *
 * Se aceptan tanto el formato antiguo (dos letras, dos o cuatro cifras) como
 * el nuevo (dos letras, dos o cuatro letras y cifras), porque ambos están en
 * uso en Chile.
 */
final class NormalizadorPatente
{
    /**
     * Formato normalizado: 2 a 4 caracteres (letras o dígitos), guion, y 2 a 4
     * dígitos. Cubre ambos formatos en uso.
     */
    private const PATRON = '/^([A-Z]{2})-?([A-Z0-9]{2,4})-?([0-9]{2,4})$/';

    /**
     * Deja la patente en su forma canónica.
     *
     * @throws InvalidArgumentException si no parece una patente chilena.
     */
    public static function normalizar(string $patente): string
    {
        // Se quita todo lo que no sea letra ni dígito: espacios, puntos,
        // comillas, guiones sueltos.
        $limpio = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $patente) ?? '');

        if (! preg_match(self::PATRON, $limpio, $coincidencias)) {
            throw new InvalidArgumentException(
                'La patente "'.$patente.'" no tiene un formato chileno reconocible. '
                .'Se espera algo como ABCD12 o ABCD-12.'
            );
        }

        return $coincidencias[1].'-'.$coincidencias[2].$coincidencias[3];
    }

    /**
     * Igual que `normalizar()`, pero devuelve null en vez de lanzar, para las
     * validaciones que quieren acumular todos los errores.
     */
    public static function intentarNormalizar(string $patente): ?string
    {
        try {
            return self::normalizar($patente);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** ¿Es un VIN chileno válido en formato internacional? */
    public static function esVinValido(string $vin): bool
    {
        $limpio = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vin) ?? '');

        // 17 caracteres. Se excluyen I, O y Q porque la norma internacional
        // los retira para evitar confusión con el 1 y el 0.
        return (bool) preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $limpio);
    }

    /** VIN normalizado a mayúsculas, sin separadores. */
    public static function normalizarVin(string $vin): ?string
    {
        $limpio = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vin) ?? '');

        return self::esVinValido($limpio) ? $limpio : null;
    }
}