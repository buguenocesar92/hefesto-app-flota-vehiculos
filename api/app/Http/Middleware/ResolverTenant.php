<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\ContextoTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el tenant de la petición para que la RLS de PostgreSQL sepa a quién
 * pertenece cada fila.
 *
 * Se aplica a todas las peticiones (ver `bootstrap/app.php`), ANTES que el
 * middleware de autenticación de la ruta. Por eso no puede usar
 * `$request->user()` todavía: resuelve el usuario por su cuenta con el guard
 * `web`, que ya lee la sesión o el token.
 *
 * Peticiones sin sesión (login, callback de Authentik, salud) pasan sin
 * transacción. Si esas rutas tocaran una tabla protegida,
 * `public.app_tenant_id()` lanzaría excepción y la petición fallaría: el
 * aislamiento falla cerrado, nunca abierto.
 *
 * El tenant SIEMPRE sale del usuario autenticado. Nunca de un parámetro de la
 * ruta ni del cuerpo de la petición: si saliera de ahí, bastaría cambiar el
 * identificador en la URL para leer los datos de otra flota.
 */
class ResolverTenant
{
    public function __construct(private readonly ContextoTenant $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = auth()->guard('web')->user();

        if ($usuario === null) {
            return $next($request);
        }

        if ($usuario->tenant_id === null) {
            // Un usuario sin flota no puede operar. Se corta aquí, antes de
            // tocar la base de datos.
            abort(403, 'La cuenta no está asociada a ninguna flota.');
        }

        return $this->contexto->ejecutarAislado(
            $usuario->tenant_id,
            static fn () => $next($request)
        );
    }
}