<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación por token de sesión entregado por Authentik.
 *
 * EL ORDEN DE LAS COSAS IMPORTA
 * -----------------------------
 * `ResolverTenant` corre antes que este middleware (está añadido globalmente
 * en `bootstrap/app.php`), y fija el tenant de la petición. Pero para eso
 * necesita un usuario, y este middleware es quien lo resuelve. Hay una
 * dependencia en círculo que se rompe así:
 *
 *   1. La petición trae un token y el identificador de la flota
 *      (`X-Flota-Slug`).
 *   2. La flota se resuelve por `vw_tenants_publicos`, que es la única tabla
 *      que se puede leer SIN contexto de tenant (la tabla `tenants` no lleva
 *      RLS por exactamente esa razón).
 *   3. Recién entonces se abre la transacción con ese tenant y se busca al
 *      usuario dentro de él.
 *
 * POR QUÉ EL TENANT SE TOMA DEL SLUG Y NO DEL CUERPO DE LA PETICIÓN
 * ------------------------------------------------------------------
 * Porque un slug NO es una credencial. El slug es público: aparece en la URL
 * de entrada y en los enlaces que la flota reparte. Con el slug se resuelve
 * el contexto, pero la autenticación sigue exigiendo el token, y la
 * comprobación final es que el usuario encontrado pertenezca a ese mismo
 * tenant. Pedir datos de otra flota no es posible: el token no existe fuera
 * de su flota.
 *
 * Por eso el tenant sigue saliendo de la identidad, y no de un parámetro que
 * el cliente pueda cambiar por gusto.
 *
 * MENSAJES
 * --------
 * El error es el mismo para "token inválido", "usuario no existe" y "usuario
 * desactivado": no hay forma de enumerar cuentas probando tokens.
 */
class AutenticarConToken
{
    public function __construct(private readonly ContextoTenant $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->cookie('flota_token');
        $slug = $request->header('X-Flota-Slug') ?? $request->cookie('flota_slug');

        if ($token === null || $token === '' || $slug === null || $slug === '') {
            return $this->noAutorizado();
        }

        // Única lectura que se hace sin contexto de tenant. Solo devuelve
        // slug, nombre y activo: no hay RUT ni correos en esta vista.
        $tenant = Tenant::query()
            ->select('id', 'slug', 'nombre', 'activo')
            ->where('slug', $slug)
            ->where('activo', true)
            ->first();

        if ($tenant === null) {
            return $this->noAutorizado();
        }

        $hash = hash('sha256', $token);

        // Dentro del tenant: la RLS garantiza que este usuario es de esta
        // flota, y no puede existir el mismo hash en dos flotas porque la
        // columna es única en toda la base.
        $usuario = $this->contexto->ejecutarAislado(
            $tenant->id,
            static fn () => Usuario::query()
                ->where('token_hash', $hash)
                ->where('activo', true)
                ->first()
        );

        if ($usuario === null || $usuario->tenant_id !== $tenant->id) {
            return $this->noAutorizado();
        }

        // Un usuario desactivado pierde el acceso de inmediato, sin esperar a
        // que expire nada.
        $this->contexto->ejecutarAislado(
            $tenant->id,
            static fn () => $usuario->forceFill(['ultimo_acceso_at' => now()])->save()
        );

        auth()->setUser($usuario);

        $request->setUserResolver(static fn () => $usuario);

        return $next($request);
    }

    private function noAutorizado(): Response
    {
        return response()->json([
            'error' => [
                'codigo' => 401,
                'mensaje' => 'No autenticado.',
            ],
        ], 401);
    }
}