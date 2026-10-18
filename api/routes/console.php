<?php

declare(strict_types=1);

use App\Console\Commands\GenerarAlertasCommand;
use App\Console\Commands\VerificarAislamientoCommand;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| Aquí vive el calendario del sistema.
|
| La generación de alertas está en una tarea programada y NO en la vista. Es
| la diferencia entre un sistema que avisa y uno que muestra un tablero con
| números: el permiso de circulación vence mañana y el chofer no va a mirar
| ninguna pantalla hoy, pero el aviso le llega igual.
|
| La expresión de cron sale de `config('flota.programacion_alertas')`, que a
| su vez lee `FLOTA_PROGRAMACION_ALERTAS` en `.env`. El valor por defecto es a
| las 06:00 hora de Chile, cuando el operador ya está trabajando y todavía
| tiene tiempo de resolver el documento antes de que el vehículo salga.
|
| `onOneServer()` exige caché compartida (Redis, que ya está configurada): si
| por error se despliegan dos réplicas de la API, la tarea corre en una sola.
*/

Schedule::command(GenerarAlertasCommand::class)
    ->cron((string) config('flota.programacion_alertas'))
    ->onOneServer()
    ->withoutOverlapping((int) config('flota.candado_alertas_minutos'))
    ->timezone(config('flota.zona_horaria'))
    ->description('Genera las alertas de vencimiento de documentos de la flota.');

/*
| Verificación del aislamiento entre flotas.
|
| No avisa de nada: falla si el sistema NO está en condiciones de proteger los
| datos. Se ejecuta al desplegar, no a diario. Ver docs/07-SEGURIDAD.md.
*/
Schedule::command(VerificarAislamientoCommand::class)
    ->hourly()
    ->onOneServer()
    ->description('Comprueba que la Row Level Security siga activa y sin vías para saltarla.');