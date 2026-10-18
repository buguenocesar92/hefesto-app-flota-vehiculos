<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AlertaController;
use App\Http\Controllers\Api\CargaCombustibleController;
use App\Http\Controllers\Api\ConductorController;
use App\Http\Controllers\Api\ConfiguracionAlertaController;
use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\KilometrajeController;
use App\Http\Controllers\Api\MantencionController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\Api\SaludController;
use App\Http\Controllers\Api\VehiculoController;
use App\Http\Controllers\Api\ViajeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de la API
|--------------------------------------------------------------------------
|
| La API no usa cookies ni sesión: es un backend para el frontend Nuxt, que se
| autentica contra Authentik y llama acá con un token. Por eso no hay
| formularios que falsear con CSRF (y `bootstrap/app.php` lo excluye de
| forma explícita, no por omisión).
|
| Todas las rutas cuelgan del middleware `token`, que resuelve el usuario; el
| tenant lo fija `ResolverTenant`, que corre a nivel de aplicación.
*/

// Estado del servicio. Sin autenticación: no dice nada de los datos de nadie.
Route::get('salud', SaludController::class)->name('salud');

Route::middleware('token')->group(function (): void {

    /*
    | Panel: el tablero de vencimientos. Es la pantalla que abre el usuario
    | cada mañana, y la que responde "¿qué está por vencer?".
    */
    Route::get('panel/resumen', [PanelController::class, 'resumen']);
    Route::get('panel/vencimientos', [PanelController::class, 'vencimientos']);
    Route::get('panel/alertas-generadas', [PanelController::class, 'alertasGeneradas']);

    /*
    | Vehículos
    */
    Route::get('vehiculos', [VehiculoController::class, 'index']);
    Route::post('vehiculos', [VehiculoController::class, 'store']);
    Route::get('vehiculos/{vehiculo}', [VehiculoController::class, 'show']);
    Route::put('vehiculos/{vehiculo}', [VehiculoController::class, 'update']);
    Route::delete('vehiculos/{vehiculo}', [VehiculoController::class, 'destroy']);

    /*
    | Documentos: el módulo central. El endpoint de vencimientos es el que
    | responde "¿qué documento está por vencer?" y ordena por días restantes.
    */
    Route::get('documentos', [DocumentoController::class, 'index']);
    Route::post('documentos', [DocumentoController::class, 'store']);
    Route::get('documentos/vencimientos', [DocumentoController::class, 'vencimientos']);
    Route::get('documentos/{documento}', [DocumentoController::class, 'show']);
    Route::put('documentos/{documento}', [DocumentoController::class, 'update']);
    Route::delete('documentos/{documento}', [DocumentoController::class, 'destroy']);

    /*
    | Alertas de vencimiento ya generadas
    */
    Route::get('alertas', [AlertaController::class, 'index']);
    Route::get('alertas/{alerta}', [AlertaController::class, 'show']);
    Route::post('alertas/{alerta}/reintentar', [AlertaController::class, 'reintentar']);

    /*
    | Kilometraje con su fuente
    */
    Route::get('kilometraje', [KilometrajeController::class, 'index']);
    Route::post('kilometraje', [KilometrajeController::class, 'store']);

    /*
    | Mantenciones
    */
    Route::get('mantenciones', [MantencionController::class, 'index']);
    Route::post('mantenciones', [MantencionController::class, 'store']);
    Route::get('mantenciones/{mantencion}', [MantencionController::class, 'show']);
    Route::put('mantenciones/{mantencion}', [MantencionController::class, 'update']);

    /*
    | Conductores y viajes
    */
    Route::get('conductores', [ConductorController::class, 'index']);
    Route::post('conductores', [ConductorController::class, 'store']);
    Route::get('conductores/{conductor}', [ConductorController::class, 'show']);

    Route::get('viajes', [ViajeController::class, 'index']);
    Route::post('viajes', [ViajeController::class, 'store']);
    Route::put('viajes/{viaje}', [ViajeController::class, 'update']);

    /*
    | Combustible y costos
    */
    Route::get('cargas-combustible', [CargaCombustibleController::class, 'index']);
    Route::post('cargas-combustible', [CargaCombustibleController::class, 'store']);
    Route::get('combustible/rendimiento', [CargaCombustibleController::class, 'rendimiento']);

    /*
    | Administración: catálogo y configuración de alertas. Reservado al
    | administrador de la flota; lo hace cumplir la Policy, no el ruteo.
    */
    Route::get('configuracion-alertas', [ConfiguracionAlertaController::class, 'index']);
    Route::post('configuracion-alertas', [ConfiguracionAlertaController::class, 'store']);
    Route::put('configuracion-alertas/{configuracion}', [ConfiguracionAlertaController::class, 'update']);
    Route::delete('configuracion-alertas/{configuracion}', [ConfiguracionAlertaController::class, 'destroy']);
});