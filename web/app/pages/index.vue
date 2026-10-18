/**
 * Tablero principal.
 *
 * Es la pantalla que el operador abre cada mañana, y responde en este orden:
 *
 *   1. ¿Qué está VENCIDO? Primero, porque ya es un problema.
 *   2. ¿Qué vence en los próximos días?
 *   3. ¿Cuándo se corrió la última generación de avisos?
 *
 * La tercera no es un detalle. Una alerta que llega tarde no sirve, así que la
 * hora de la última revisión se muestra siempre, incluso con el tablero
 * limpio. Un tablero todo en verde sin sello de tiempo hace creer que los datos
 * son de ahora.
 */
<script setup lang="ts">
const { resumen, vencimientos, alertasGeneradas, proxy } = useFlota()

const { data: resumenData, pending: resumenCargando } = resumen()
const { data: vencimientosData, pending: vencimientosCargando } = vencimientos(90)
const { data: ultimaGeneracion } = alertasGeneradas()

useHead({ title: 'Tablero de vencimientos · Flota de Vehículos' })

const documentos = computed(() => vencimientosData.value?.datos ?? [])

const vencidos = computed(() =>
    documentos.value.filter((d: any) => d.estado === 'vencido')
)

const porVencer = computed(() =>
    documentos.value.filter((d: any) => d.estado === 'por_vencer')
)

/** Texto de "hace cuánto". La hora exacta se muestra al pasar el cursor. */
const antiguedadDeLaRevision = computed(() => {
    const iso = ultimaGeneracion.value?.ultima_generacion

    if (!iso) {
        return 'Nunca se ha generado alertas.'
    }

    const minutos = Math.floor((Date.now() - new Date(iso).getTime()) / 60000)

    if (minutos < 60) return `Hace ${minutos} minutos`
    if (minutos < 1440) return `Hace ${Math.floor(minutos / 60)} horas`

    return `Hace ${Math.floor(minutos / 1440)} días`
})

const descargadas = async () => {
    await navigateTo(proxy('/documentos/vencimientos', { dias: 3650 }))
}
</script>

<template>
    <main class="tablero">
        <header class="tablero__cabecera">
            <h1>Vencimientos de documentos</h1>
            <p class="tablero__revision" :title="ultimaGeneracion?.ultima_generacion ?? ''">
                Última revisión de alertas: <strong>{{ antiguedadDeLaRevision }}</strong>
                <span v-if="ultimaGeneracion?.nunca_ejecutada" class="tablero__alerta-inline">
                    — la tarea programada todavía no se ha ejecutado.
                </span>
            </p>
        </header>

        <!--
            Resumen. Los dos estados van separados a propósito: el total de
            documentos vencidos y el de vehículos en el taller son números
            distintos, y sumarlos en un único indicador taparía el caso de un
            vehículo que está en ambas.
        -->
        <section class="tablero__indicadores" aria-label="Resumen de la flota">
            <article class="indicador indicador--alerta">
                <span class="indicador__valor">{{ resumenData?.documentos?.vencido ?? '—' }}</span>
                <span class="indicador__etiqueta">Documentos vencidos</span>
            </article>

            <article class="indicador indicador--aviso">
                <span class="indicador__valor">{{ resumenData?.documentos?.por_vencer ?? '—' }}</span>
                <span class="indicador__etiqueta">Por vencer</span>
            </article>

            <article
                v-if="(resumenData?.vehiculos_en_taller_y_vencidos ?? 0) > 0"
                class="indicador indicador--alerta"
            >
                <span class="indicador__valor">
                    {{ resumenData?.vehiculos_en_taller_y_vencidos }}
                </span>
                <span class="indicador__etiqueta">En mantención y vencidos</span>
            </article>

            <article class="indicador">
                <span class="indicador__valor">{{ resumenData?.vehiculos_activos ?? '—' }}</span>
                <span class="indicador__etiqueta">Vehículos activos</span>
            </article>

            <article class="indicador">
                <span class="indicador__valor">{{ resumenData?.alertas_pendientes ?? '—' }}</span>
                <span class="indicador__etiqueta">Avisos pendientes</span>
            </article>
        </section>

        <section aria-label="Documentos vencidos">
            <h2 class="tablero__seccion">Vencidos ({{ vencidos.length }})</h2>
            <p v-if="vencidos.length === 0" class="tablero__vacio">
                No hay documentos vencidos.
            </p>
            <ul v-else class="lista-vencimientos">
                <li v-for="documento in vencidos" :key="documento.id" class="lista-vencimientos__item">
                    <span class="lista-vencimientos__sujeto">
                        {{ documento.vehiculo_patente || documento.conductor }}
                    </span>
                    <span class="lista-vencimientos__tipo">{{ documento.tipo_documento }}</span>
                    <span class="lista-vencimientos__fecha">
                        Venció el {{ documento.fecha_efectiva }}
                    </span>
                    <span class="etiqueta etiqueta--alerta">
                        Vencido hace {{ Math.abs(documento.dias_restantes) }} días
                    </span>
                </li>
            </ul>
        </section>

        <section aria-label="Documentos por vencer">
            <h2 class="tablero__seccion">Por vencer en los próximos 90 días ({{ porVencer.length }})</h2>
            <p v-if="porVencer.length === 0" class="tablero__vacio">
                No hay documentos por vencer en la ventana consultada.
            </p>
            <ul v-else class="lista-vencimientos">
                <li v-for="documento in porVencer" :key="documento.id" class="lista-vencimientos__item">
                    <span class="lista-vencimientos__sujeto">
                        {{ documento.vehiculo_patente || documento.conductor }}
                    </span>
                    <span class="lista-vencimientos__tipo">{{ documento.tipo_documento }}</span>
                    <span class="lista-vencimientos__fecha">{{ documento.fecha_efectiva }}</span>
                    <span class="etiqueta" :class="documento.dias_restantes <= 7 ? 'etiqueta--alerta' : 'etiqueta--aviso'">
                        Faltan {{ documento.dias_restantes }} días
                    </span>
                    <!--
                        Si el vencimiento viene de una regla de frecuencia, se
                        marca como estimado. El operador tiene que saber que no
                        es una fecha legal sino un cálculo.
                    -->
                    <span v-if="documento.estimada" class="etiqueta etiqueta--neutro">
                        Fecha estimada
                    </span>
                </li>
            </ul>
        </section>

        <p v-if="resumenCargando || vencimientosCargando" class="tablero__vacio">
            Cargando vencimientos…
        </p>
    </main>
</template>

<style scoped>
.tablero {
    max-width: 1100px;
    margin: 0 auto;
    padding: 1.5rem 1rem 3rem;
}

.tablero__cabecera h1 {
    margin: 0 0 0.25rem;
    font-size: 1.6rem;
}

.tablero__revision {
    margin: 0 0 1.5rem;
    color: var(--texto-suave);
    font-size: 0.9rem;
}

.tablero__alerta-inline {
    color: var(--texto-alerta);
}

.tablero__indicadores {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 0.75rem;
    margin-bottom: 2rem;
}

.tablero__seccion {
    font-size: 1.1rem;
    margin: 2rem 0 0.75rem;
}

.tablero__vacio {
    color: var(--texto-suave);
    font-style: italic;
}

.lista-vencimientos {
    list-style: none;
    padding: 0;
    margin: 0;
}

.lista-vencimientos__item {
    display: flex;
    gap: 0.75rem;
    align-items: center;
    flex-wrap: wrap;
    padding: 0.6rem 0;
    border-bottom: 1px solid var(--borde);
}

.lista-vencimientos__sujeto {
    font-weight: 600;
    min-width: 8rem;
}

.lista-vencimientos__tipo {
    min-width: 12rem;
}

.lista-vencimientos__fecha {
    color: var(--texto-suave);
}
</style>