/**
 * Tarjeta de estado de un vehículo, en DOS colores.
 *
 * El motivo de que este componente exista: un vehículo en mantención con el
 * seguro vencido tiene que verse en las dos dimensiones. Si se eligiera un solo
 * color según el estado operativo, el seguro vencido quedaría tapado detrás de
 * "en mantención", que es exactamente el problema que el dueño de la flota
 * señaló.
 *
 * Por eso hay dos indicadores y ninguno cede ante el otro.
 */
<script setup lang="ts">
interface EstadoVehiculo {
    operativo: string
    operativo_etiqueta: string
    documental: string
    documental_etiqueta: string
    hay_algo_critico: boolean
    en_taller_y_vencido: boolean
    alertas_pendientes: number
}

const props = defineProps<{
    patente: string
    descripcion?: string
    estado: EstadoVehiculo
}>()

const clasesOperativas: Record<string, string> = {
    disponible: 'estado--ok',
    asignado: 'estado--info',
    en_mantencion: 'estado--aviso',
    fuera_de_servicio: 'estado--alerta',
    retirado: 'estado--neutro',
}

const clasesDocumentales: Record<string, string> = {
    vigente: 'estado--ok',
    por_vencer: 'estado--aviso',
    vencido: 'estado--alerta',
    sin_fecha: 'estado--neutro',
}
</script>

<template>
    <article class="tarjeta-vehiculo">
        <header class="tarjeta-vehiculo__cabecera">
            <div>
                <strong class="tarjeta-vehiculo__patente">{{ props.patente }}</strong>
                <span v-if="props.descripcion" class="tarjeta-vehiculo__descripcion">
                    {{ props.descripcion }}
                </span>
            </div>

            <!--
                Los dos estados van juntos. El documental aparece primero
                cuando está vencido, porque es el que frena al vehículo.
            -->
            <div class="tarjeta-vehiculo__estados">
                <span class="etiqueta" :class="clasesDocumentales[props.estado.documental]">
                    Documentos: {{ props.estado.documental_etiqueta }}
                </span>
                <span class="etiqueta" :class="clasesOperativas[props.estado.operativo]">
                    {{ props.estado.operativo_etiqueta }}
                </span>
            </div>
        </header>

        <!--
            Aviso específico del caso combinado. No es decoración: es la
            situación en la que el vehículo sale del taller sin resolver el
            documento vencido.
        -->
        <p v-if="props.estado.en_taller_y_vencido" class="tarjeta-vehiculo__critico">
            Está en mantención <strong>y</strong> tiene documentos vencidos.
            Hay que resolver el documento antes de volver a salir.
        </p>

        <p v-if="props.estado.alertas_pendientes > 0" class="tarjeta-vehiculo__alertas">
            {{ props.estado.alertas_pendientes }} aviso(s) de vencimiento pendiente(s).
        </p>
    </article>
</template>

<style scoped>
.tarjeta-vehiculo {
    border: 1px solid var(--borde);
    border-radius: 8px;
    padding: 1rem;
    background: var(--fondo-tarjeta);
}

.tarjeta-vehiculo__cabecera {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
}

.tarjeta-vehiculo__patente {
    display: block;
    font-size: 1.05rem;
    letter-spacing: 0.02em;
}

.tarjeta-vehiculo__descripcion {
    color: var(--texto-suave);
    font-size: 0.9rem;
}

.tarjeta-vehiculo__estados {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.tarjeta-vehiculo__critico {
    margin: 0.75rem 0 0;
    padding: 0.6rem 0.75rem;
    border-left: 3px solid var(--alerta);
    background: var(--fondo-alerta);
    color: var(--texto-alerta);
    font-size: 0.92rem;
}

.tarjeta-vehiculo__alertas {
    margin: 0.5rem 0 0;
    color: var(--texto-suave);
    font-size: 0.88rem;
}
</style>