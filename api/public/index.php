<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// El modo de mantenimiento se comprueba antes de cargar el framework: si esta
// activo, ninguna peticion llega a tocar la base de datos.
if (file_exists($mantenimiento = __DIR__.'/../storage/framework/maintenance.php')) {
    require $mantenimiento;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());