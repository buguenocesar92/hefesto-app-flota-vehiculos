# 01 - Arquitectura

## 1. Qué se construyó y para qué

Una aplicación web para documentar una flota de vehículos y **avisar antes de
que los documentos venzan**.

El problema de fondo no es la información: es el **silencio**. Un permiso de
circulación vence mañana y el chofer no lo sabe. Un seguro está vencido y
nadie se enteró hasta el accidente. Los documentos vencen sin hacer ruido, y el
funcionamiento normal del negocio se detiene de golpe.

Por eso el centro del sistema son los **vencimientos** y no las reparaciones.
Las mantenciones están porque una flota sin historial de mantenciones tampoco es
una flota controlada, pero son apoyo.

## 2. Las cuatro decisiones que explican la arquitectura

### 2.1 El vencimiento se calcula, no se guarda

El estado "vencido" **no está en la base**. Se calcula desde la fecha en cada
lectura.

Si se guardara, habría dos fuentes de verdad. Basta con que alguien corrigiera
la fecha de un documento desde el formulario de edición (una operación
legítima) y el estado guardado quedaría diciendo "vencido": el permiso aparecería
caducado siendo válido. O al revés, que es el caso que mata.

`App\Enums\EstadoTemporalDocumento` y `Documento::estadoTemporal()` resuelven
esto. Ver [`02-MODELO-DE-DATOS.md`](02-MODELO-DE-DATOS.md).

### 2.2 La alerta se genera, y después se envía

Son dos pasos distintos, en dos procesos distintos:

| Paso | Dónde | Cuándo |
|---|---|---|
| Generar la alerta | Tarea programada (`flota:generar-alertas`) | Todos los días a las 06:00 |
| Enviar la alerta | Worker de cola (`queue:work`) | Inmediatamente después |

Si el generador escribiera directo en el correo, no habría forma de saber que ya
se avisó, y la tarea del día siguiente mandaría lo mismo otra vez. El operador
recibiría el mismo correo siete veces y **dejaría de mirarlos**, que es
exactamente el problema que el sistema vino a resolver.

### 2.3 La no duplicación la garantiza la base, no el código

Cada alerta lleva una `periodo_clave` **determinista**:

```
v:2026-05-20:umbral:30      a 30 días del vencimiento del 20 de mayo
v:2026-05-20:umbral:15      a 15 días del mismo vencimiento
v:2026-05-20:vencido        ya vencido
```

Y la tabla tiene un **índice único** sobre `(documento_id, periodo_clave)`, con
inserción `ON CONFLICT DO NOTHING`.

La diferencia es dónde está la garantía. Con un índice único, la promesa es
"esto es imposible". Con una comprobación en el código, la promesa es "esto no
pasa, mientras nadie modifique el código". La segunda se rompe sola, con el
primer cambio urgente a las once de la noche.

Hay tres capas, y apuntan todas al mismo objetivo:

1. `onOneServer()`: si hay dos réplicas, la tarea corre en una.
2. `withoutOverlapping()`: una ejecución lenta no se solapa con la siguiente.
3. El índice único: aunque las dos fallaran, la base no acepta el duplicado.

La tercera es la que no puede fallar. Hay una prueba dedicada a eso:
`api/tests/Feature/Alertas/AlertaUnicaPorDocumentoYPeriodoTest.php`.

### 2.4 Dos ejes de estado, nunca uno

Un vehículo puede estar **en mantención** y tener documentos **vencidos** al
mismo tiempo. Con un solo estado, hay que elegir uno, y el otro desaparece.

`App\Services\Vehiculos\EstadoOperativoVehiculo` devuelve dos estructuras y
agrega una tercera:

```php
[
    'operativo' => 'en_mantencion',   // ¿se puede usar el vehículo ahora?
    'documental' => 'vencido',        // ¿está todo en regla?
    'en_taller_y_vencido' => true,    // el caso que el dueño señaló
    'alertas_pendientes' => 2,
]
```

## 3. Componentes

```
                    ┌─────────────────┐
  Navegador ───────▶│   Nuxt 3 (SSR)  │
                    └────────┬────────┘
                             │  token de sesión + slug de flota
                             ▼
                    ┌─────────────────┐
                    │  API Laravel 12 │
                    │  ├─ auth        │──▶ Authentik (OIDC)
                    │  ├─ RLS tenant  │──▶ PostgreSQL 16
                    │  └─ RLS tenant  │──▶ Redis (caché y colas)
                    └─────────────────┘
                             ▲
                    ┌────────┴────────┐
                    │  Planificador   │  una sola instancia
                    └─────────────────┘
```

| Componente | Versión | Rol |
|---|---|---|
| Laravel | 12.x | API, reglas de negocio, generación de alertas |
| PostgreSQL | 16.4 | Datos y **aislamiento real** entre flotas |
| Redis | 7.2.5 | Caché, colas y candado de la tarea programada |
| Nuxt | 3.13.2 | Interfaz con renderizado en el servidor |
| Authentik | 2024.6 | Identidad (OpenID Connect) |
| PHP | 8.3 | Lenguaje de la API |

### Por qué el planificador va en un contenedor aparte

Si el planificador viviera dentro del contenedor de la API y se desplegara dos
réplicas, cada una correría las tareas. `onOneServer()` y el candado de Redis
evitan la mayoría de los duplicados, pero separar el proceso hace el problema
estructural visible de inmediato: si `scheduler` corre una vez, corre una vez.

### Por qué el tablero usa SSR

Con el cliente renderizando, la primera pantalla sería un spinner. En un tablero
de vencimientos, un spinner se lee como **"no hay nada vencido"**, que es la
peor respuesta posible para este producto.

## 4. Flujo completo de una alerta

1. **06:00** arranca `flota:generar-alertas`.
2. Adquiere el candado en Redis (30 minutos por defecto).
3. Por cada flota activa, abre una transacción y fija `app.tenant_id`.
4. Recorre los documentos **vigentes**.
5. Para cada uno calcula la fecha efectiva y su origen:
   - `documento`: la fecha impresa. Es un hecho.
   - `regla_frecuencia`: una periodicidad **confirmada**. Es una estimación.
   - `ninguno`: no hay nada que avisar. Se cuenta y se muestra.
6. Para cada umbral configurado que corresponda, arma la `periodo_clave` e
   inserta con `ON CONFLICT DO NOTHING`.
7. Si se insertó de verdad y el envío está activo, despacha el trabajo de envío.
8. Muestra el resultado: nuevas, omitidas por duplicado, documentos sin fecha.

## 5. Lo que este sistema NO hace

- **No declara la vigencia legal de nada.** El detalle está en el
  [README](../README.md) y en [`02`](02-MODELO-DE-DATOS.md).
- **No inventa plazos legales.** El catálogo se carga sin ellos, a propósito.
- **No guarda kilometraje ni rendimiento como campos.** Los dos se derivan de
  lecturas que dicen de dónde salieron.
- **No repara vehículos.** Registra mantenciones, no las ejecuta.
- **No reemplaza el control documental de la flota.** Ayuda a no olvidar.

## 6. Estado

**Código escrito, sin ejecutar.** No se levantó ningún contenedor, no se corrió
ninguna migración y no se ejecutó ninguna prueba. El detalle está en
[`10-PRUEBAS-Y-ESTADO.md`](10-PRUEBAS-Y-ESTADO.md).