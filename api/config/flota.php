<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuracion del dominio de flota
|--------------------------------------------------------------------------
|
| Aqui viven los parametros que son DECISION DE NEGOCIO de Hefesto, no
| parametros de infraestructura. Se separan de `config/app.php` a proposito:
| cuando alguien pregunte "de donde sale este numero", la respuesta tiene
| que ser un archivo con nombre.
|
| AVISO IMPORTANTE SOBRE PLAZOS
| -----------------------------
| Los numeros de `umbrales_alerta_dias` son plazos de AVISO INTERNO
| ("con 30 dias de anticipacion avisame"), NO plazos legales. Son una
| decision de Hefesto para que al operador le alcance el tiempo. El sistema
| NUNCA inventa la fecha legal de vencimiento de un documento: esa fecha se
| carga tal como viene impresa en el documento, y los plazos legales que
|reuven por periodicidad de inspeccion se configuran uno por uno, marcados
| como pendientes de confirmacion. Ver docs/02-MODELO-DE-DATOS.md,
| seccion "Plazos legales: lo que el sistema no inventa".
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Umbrales de aviso
    |--------------------------------------------------------------------------
    |
    | Dias de anticipacion con los que se avisa, de mayor a menor. Si un
    | documento vence en 25 dias, salta el umbral de 30 (aviso temprano) y
    | queda guardado; si vence en 10 dias, saltan los de 30 y 15.
    |
    | Estos son plazos de AVISO, no legales. Son valores por defecto de la
    | semilla; cada flota puede cambiarlos en Configuracion de alertas.
    */
    'umbrales_alerta_dias' => [60, 30, 15, 7, 1],

    /*
    |--------------------------------------------------------------------------
    | Dias despues del vencimiento
    |--------------------------------------------------------------------------
    |
    | Cuantos dias sigue insistiendo el sistema despues de la fecha de
    | vencimiento. En 0 se genera el aviso "vencido" el mismo dia.
    |
    | Una sola vez por periodo: si el documento sigue vencido mañana, no se
    | manda un aviso nuevo cada dia. Eso lo garantiza el indice unico
    | (documento_id, periodo_clave) de la tabla alertas_documento.
    */
    'dias_insistencia_vencido' => 0,

    /*
    |--------------------------------------------------------------------------
    | Zona horaria de calculo
    |--------------------------------------------------------------------------
    |
    | Los dias que faltan se calculan en hora de Chile, no en UTC. Un
    | documento que vence "el 20" tiene que contar 10 dias restantes a las
    | 23:50 del dia 10, en cualquier caso. Guardar en UTC y comparar en
    | UTC hace que un documento parezca vencido un dia antes en verano.
    */
    'zona_horaria' => env('FLOTA_ZONA_HORARIA', 'America/Santiago'),

    /*
    |--------------------------------------------------------------------------
    | Tarea programada de vencimientos
    |--------------------------------------------------------------------------
    |
    | Las alertas se generan desde la tarea programada, nunca desde la vista.
    | Una alerta que depende de que alguien mire una pantalla no es una
    | alerta: es una forgotiz con pasos.
    |
    | La expresion de cron se deja en .env para poder ajustar la frecuencia
    | sin tocar codigo. La frecuencia por defecto es diaria a las 06:00 hora
    | de Chile.
    */
    'programacion_alertas' => env('FLOTA_PROGRAMACION_ALERTAS', '0 10 * * *'),

    /*
    |--------------------------------------------------------------------------
    | Minutos que el candado de la tarea puede quedar tomado
    |--------------------------------------------------------------------------
    |
    | `withoutOverlapping` evita que dos ejecuciones simultaneas generen
    | alertas. Este valor es la ventana maxima del candado: si una
    | ejecucion se cuelga, la siguiente puede entrar igual.
    */
    'candado_alertas_minutos' => (int) env('FLOTA_CANDADO_ALERTAS_MINUTOS', 30),

    /*
    |--------------------------------------------------------------------------
    | Envio de alertas
    |--------------------------------------------------------------------------
    |
    | Apagado por defecto en desarrollo: el generador calcula y registra las
    | alertas, pero no despacha correos hasta que la cadena de confianza
    | (Authentik, SMTP, cola) este probada de punta a punta.
    */
    'envio_activo' => (bool) env('FLOTA_ALERTAS_ENVIO_ACTIVO', false),

    /*
    |--------------------------------------------------------------------------
    | Origen del vencimiento
    |--------------------------------------------------------------------------
    |
    | El sistema distingue siempre de donde sale la fecha que usa para
    | avisar:
    |
    |   'documento'          : fecha impresa en el documento. Es la real.
    |   'regla_frecuencia'   : calculada por una periodicidad configurada.
    |                          Es una ESTIMACION de trabajo: la interfaz la
    |                          muestra marcada como calculada y nunca como
    |                          vencimiento legal.
    */
    'origenes_vencimiento' => ['documento', 'regla_frecuencia'],

    /*
    |--------------------------------------------------------------------------
    | Rendimiento de combustible
    |--------------------------------------------------------------------------
    |
    | Litros minimos para calcular rendimiento. Con una sola carga no hay
    | diferencia de kilometraje con que dividir, asi que el sistema no
    | inventa un numero: no muestra rendimiento hasta tener este minimo.
    */
    'litros_minimos_rendimiento' => (float) env('FLOTA_LITROS_MINIMOS_RENDIMIENTO', 5),

    /*
    |--------------------------------------------------------------------------
    | Tamano de pagina
    |--------------------------------------------------------------------------
    */
    'por_pagina' => (int) env('FLOTA_POR_PAGINA', 25),

    /*
    |--------------------------------------------------------------------------
    | Dominios de correo permitidos en Authentik
    |--------------------------------------------------------------------------
    |
    | Lista separada por comas, vacia significa "sin restriccion". Si se
    | configura, el login solo acepta usuarios cuyo correo pertenezca a
    | alguno de estos dominios. Ver docs/05-CONFIGURACION.md.
    */
    'dominios_permitidos' => array_filter(
        array_map('trim', explode(',', (string) env('FLOTA_DOMINIOS_PERMITIDOS', '')))
    ),
];