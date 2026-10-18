<template>
    <div class="aplicacion">
        <header class="aplicacion__barra">
            <NuxtLink to="/" class="aplicacion__marca">Flota de Vehículos</NuxtLink>
            <nav class="aplicacion__navegacion">
                <NuxtLink to="/">Tablero</NuxtLink>
                <NuxtLink to="/vehiculos">Vehículos</NuxtLink>
                <NuxtLink to="/alertas">Avisos</NuxtLink>
            </nav>
            <span class="aplicacion__marca-derecha">Hefesto Studio</span>
        </header>

        <NuxtPage />
    </div>
</template>

<style scoped>
.aplicacion__barra {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.75rem 1.5rem;
    background: #1c2024;
    color: #fff;
}

.aplicacion__marca {
    color: #fff;
    text-decoration: none;
    font-weight: 600;
}

.aplicacion__navegacion {
    display: flex;
    gap: 1rem;
}

.aplicacion__navegacion a {
    color: #cbd3da;
    text-decoration: none;
    padding: 0.25rem 0;
    border-bottom: 2px solid transparent;
}

.aplicacion__navegacion a.router-link-active {
    color: #fff;
    border-bottom-color: #6ea8fe;
}

.aplicacion__marca-derecha {
    color: #8b949e;
    font-size: 0.85rem;
}
</style>