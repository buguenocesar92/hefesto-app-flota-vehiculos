/**
 * Acceso a la API desde el servidor.
 *
 * TODAS LAS LLAMADAS PASAN POR AQUÍ, y por una razón concreta: el token de
 * sesión viaja en el servidor y nunca llega al navegador. El frontend se
 * autentica contra Authentik, entrega el token a Nuxt una vez, y desde ese
 * momento es la API la que lo usa. Si el token anduviera por todas partes, un
 * error de configuración (una variable pública, un registro en consola)
 * terminaría exponiéndolo.
 *
 * La cabecera `X-Flota-Slug` viaja con cada petición porque es lo que permite
 * a la API resolver el contexto de tenant antes de autenticar. El slug no es
 * una credencial: es público, y la autenticación sigue exigiendo el token.
 */
export default defineEventHandler(async (event) => {
    const config = useRuntimeConfig(event)

    // En el servidor, el token viene de la cookie de sesión httpOnly que
    // estableció el flujo de autenticación. Nunca se lee del cliente.
    const token = getCookie(event, 'flota_token')
    const slug = getCookie(event, 'flota_slug')

    const ruta = getRequestURL(event).path.replace(/^\/api\/proxy/, '')
    const consulta = getRequestURL(event).search

    if (!token || !slug) {
        throw createError({
            statusCode: 401,
            statusMessage: 'No autenticado',
            data: { mensaje: 'La sesión no está iniciada o no es válida.' },
        })
    }

    try {
        return await $fetch(`${config.apiBase}/api${ruta}${consulta}`, {
            headers: {
                Authorization: `Bearer ${token}`,
                'X-Flota-Slug': slug,
                Accept: 'application/json',
            },
        })
    } catch (error: any) {
        // El error se re-lanza con el estado que devolvió la API, sin la
        // traza: la interna nunca viaja al navegador.
        throw createError({
            statusCode: error?.response?.status ?? 500,
            statusMessage: error?.response?._data?.error?.mensaje ?? 'Error al consultar la API.',
        })
    }
})