<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Semilla general.
 *
 * El orden importa: primero el catálogo de tipos de documento, que es global
 * y del que dependen documentos, reglas de frecuencia y configuración de
 * alertas.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TipoDocumentoSeeder::class,
            FlotaEjemploSeeder::class,
        ]);
    }
}