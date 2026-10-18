<?php

declare(strict_types=1);

namespace App\Services\Kilometraje;

use RuntimeException;

/**
 * Error de coherencia en una lectura de kilometraje.
 *
 * Excepción propia y no un `ValidationException`, porque el error no es de
 * formulario sino de modelo: aunque alguien escriba el dato a mano por SQL o
 * por consola, la regla se sostiene.
 */
class KilometrajeIncoherente extends RuntimeException {}