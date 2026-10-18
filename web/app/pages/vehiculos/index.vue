/**
 * Listado de vehículos.
 *
 * Cada tarjeta muestra los DOS estados. No es una decisión de diseño: es el
 * requisito de que un vehículo en mantención con documentos vencidos tiene
 * que verse en las dos dimensiones.
 */
<script setup lang="ts">
const { vehiculos } = useFlota()

const { data, pending } = vehiculos({ solo_activos: true })

useHead({ title: 'Vehículos · Flota de Vehículos' })

const lista = computed(() => data.value?.data ?? [])
</script>

<template>
    <main class="pagina">
        <h1>Vehículos</h1>

        <p v-if="pending" class="pagina__vacio">Cargando vehículos…</p>
        <p v-else-if="lista.length === 0" class="pagina__vacio">
            Todavía no hay vehículos registrados.
            <NuxtLink to="/vehiculos/nuevo">Registrar el primero</NuxtLink>.
        </p>

        <div v-else class="rejilla">
            <TarjetaVehiculo
                v-for="vehiculo in lista"
                :key="vehiculo.id"
                :patente="vehiculo.patente"
                :descripcion="`${vehiculo.marca} ${vehiculo.modelo} · ${vehiculo.anio}`"
                :estado="vehiculo.estado_calculado"
            />
        </div>
    </main>
</template>

<style scoped>
.pagina {
    max-width: 1100px;
    margin: 0 auto;
    padding: 1.5rem 1rem 3rem;
}

.pagina__vacio {
    color: var(--texto-suave);
    font-style: italic;
}

.rejilla {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
}
</style>