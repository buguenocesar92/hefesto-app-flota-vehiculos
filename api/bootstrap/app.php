<?php

declare(strict_types=1);

use App\Http\Middleware\AutenticarConToken;
use App\Http\Middleware\ResolverTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'token' => AutenticarConToken::class,
        ]);

        // El tenant se resuelve SIEMPRE desde el usuario autenticado. Nunca
        // desde un parametro de la ruta ni desde el cuerpo de la peticion:
        // eso permitiria pedir los datos de otra flota cambiando el id.
        $middleware->append(ResolverTenant::class);

        // La API es sin cookies y sin sesion: no hay formulario que falsificar,
        // pero la excepcion queda explicita para que nadie la quite creyendo
        // que es "innecesaria en una API".
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // Cabeceras de seguridad en toda respuesta de la API.
        $middleware->headers([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $peticion) {
            if (! $peticion->is('api/*')) {
                return null;
            }

            // Un 403 confirmaria que el recurso existe. Para el aislamiento
            // entre flotas la respuesta correcta es 404: el recurso no existe
            // a ojos de quien pregunta.
            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'error' => [
                        'codigo' => $e->getStatusCode(),
                        'mensaje' => $e->getMessage() !== ''
                            ? $e->getMessage()
                            : 'No se pudo completar la solicitud.',
                    ],
                ], $e->getStatusCode());
            }

            // Cualquier otra excepcion se registra entera en el log (con
            // identificador de correlacion) y al cliente solo le llega un
            // mensaje generico. Nunca una traza.
            report($e);

            return response()->json([
                'error' => [
                    'codigo' => 500,
                    'mensaje' => 'Ocurrio un error interno. El equipo de Hefesto fue notificado.',
                ],
            ], 500);
        });

        // Una violacion de RLS (SQLSTATE 42501, insufficient_privilege) es un
        // incidente de aislamiento, no un error de usuario. Queda registrada
        // con voz propia para que salte en la revision de logs.
        $exceptions->render(function (Throwable $e, Request $peticion) {
            if ($peticion->is('api/*') && self::esViolacionDeAislamiento($e)) {
                report($e);

                return response()->json([
                    'error' => [
                        'codigo' => 500,
                        'mensaje' => 'La operacion no pudo completarse de forma segura.',
                    ],
                ], 500);
            }

            return null;
        });
    })
    ->create();

/**
 * Detecta el error que lanza `public.app_tenant_id()` cuando una operacion
 * llego a la base sin contexto de tenant.
 */
function esViolacionDeAislamiento(Throwable $e): bool
{
    do {
        if (($e->getCode() === '42501') || str_contains($e->getMessage(), 'app.tenant_id')) {
            return true;
        }

        $e = $e->getPrevious();
    } while ($e !== null);

    return false;
}