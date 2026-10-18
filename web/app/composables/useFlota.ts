/**
 * Acceso a la API de la flota, desde los componentes y las páginas.
 *
 * Todas las llamadas van por el proxy del servidor (`/api/proxy/...`), que es
 * el único que tiene el token. Por eso este composable no necesita manejar
 * credenciales: solo arma la ruta.
 *
 * `useAsyncData` se usa siempre que la página necesite renderizado en el
 * servidor. Un `useFetch` en el cliente mostraría una pantalla vacía antes de
 * que llegue el dato, y en un tablero de vencimientos una pantalla vacía se
 * lee como "no hay nada vencido", que es la peor respuesta posible.
 */
export function useFlota() {
    const proxy = (ruta: string, consulta?: Record<string, any>) => {
        const parametros = new URLSearchParams()

        for (const [clave, valor] of Object.entries(consulta ?? {})) {
            if (valor !== undefined && valor !== null && valor !== '') {
                parametros.set(clave, String(valor))
            }
        }

        const sufijo = parametros.toString() ? `?${parametros}` : ''

        return `/api/proxy${ruta}${sufijo}`
    }

    /** Resumen del tablero: qué está vencido y qué está por vencer. */
    const resumen = () =>
        useAsyncData('panel-resumen', () => $fetch(proxy('/panel/resumen')), {
            server: true,
            lazy: false,
        })

    /**
     * Vencimientos ordenados por urgencia.
     *
     * `dias` acota la ventana: por defecto, 90 días más todo lo ya vencido.
     */
    const vencimientos = (dias = 90) =>
        useAsyncData(
            `vencimientos-${dias}`,
            () => $fetch(proxy('/documentos/vencimientos', { dias })),
            { server: true, lazy: false }
        )

    const vehiculos = (filtros: Record<string, any> = {}) =>
        useAsyncData(
            `vehiculos-${JSON.stringify(filtros)}`,
            () => $fetch(proxy('/vehiculos', filtros)),
            { server: true }
        )

    const vehiculo = (id: string) =>
        useAsyncData(`vehiculo-${id}`, () => $fetch(proxy(`/vehiculos/${id}`)), {
            server: true,
        })

    const alertas = (filtros: Record<string, any> = {}) =>
        useAsyncData(
            `alertas-${JSON.stringify(filtros)}`,
            () => $fetch(proxy('/alertas', filtros)),
            { server: true }
        )

    /**
     * Cuándo se corrió la última generación de alertas.
     *
     * Se consulta por separado y se muestra siempre, incluso cuando el
     * tablero está limpio. Un tablero todo en verde sin la hora de la última
     * revisión no permite saber si los datos son viejos.
     */
    const alertasGeneradas = () =>
        useAsyncData('alertas-generadas', () => $fetch(proxy('/panel/alertas-generadas')), {
            server: true,
            lazy: true,
        })

    const configuracionAlertas = () =>
        useAsyncData('configuracion-alertas', () => $fetch(proxy('/configuracion-alertas')), {
            server: true,
        })

    return {
        proxy,
        resumen,
        vencimientos,
        vehiculos,
        vehiculo,
        alertas,
        alertasGeneradas,
        configuracionAlertas,
    }
}