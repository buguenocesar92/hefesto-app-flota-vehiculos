/**
 * Configuración de Nuxt.
 *
 * SSR ACTIVADO A PROPÓSITO
 * ------------------------
 * El tablero de vencimientos se abre en el servidor: el HTML llega con los
 * vencimientos ya dentro. Con el cliente renderizando, la primera pantalla
 * mostraría un spinner y el operador esperaría para enterarse de que hay un
 * permiso vencido.
 *
 * VERSIONES FIJADAS
 * -----------------
 * Todas las dependencias van con versión exacta, sin `^` ni `~`. Un
 * despliegue que se actualiza solo es un despliegue que cambió sin que nadie
 * lo decidiera, y un sistema de alertas tiene que ser predecible en su
 * versión.
 */
export default defineNuxtConfig({
    compatibilityDate: '2024-10-01',

    ssr: true,

    devtools: { enabled: false },

    runtimeConfig: {
        // Valores del servidor. NUNCA se exponen al navegador.
        apiBase: process.env.FLOTA_API_BASE || 'http://api:8000',

        public: {
            // La URL de Authentik sí es pública: es donde el usuario inicia
            // sesión en el navegador.
            authentikUrl: process.env.FLOTA_AUTHENTIK_URL || '',
            authentikClientId: process.env.FLOTA_AUTHENTIK_CLIENT_ID || '',
        },
    },

    app: {
        head: {
            title: 'Flota de Vehículos · Hefesto Studio',
            htmlAttrs: { lang: 'es-CL' },
            meta: [
                { charset: 'utf-8' },
                { name: 'viewport', content: 'width=device-width, initial-scale=1' },
                {
                    name: 'description',
                    content: 'Control de vencimientos documentales y operación de flota.',
                },
            ],
        },
    },

    css: ['~/assets/css/main.css'],

    typescript: {
        strict: true,
    },
})