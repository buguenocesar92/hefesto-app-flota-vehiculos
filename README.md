# Gestión de Flota de Vehículos

**Hefesto Studio** — aplicación propia para controlar los documentos de una
flota de vehículos y avisar antes de que venzan.

> **Estado: código escrito, sin ejecutar.**
> En el entorno donde se construyó esta aplicación no había Docker, ni PHP, ni
> Composer, ni PostgreSQL. Nada de lo que hay en esta carpeta se ha levantado,
> no se ha migrado y no se ha probado. El detalle de qué se probó y qué no
> está en [`docs/10-PRUEBAS-Y-ESTADO.md`](docs/10-PRUEBAS-Y-ESTADO.md).
> Presentar esto como funcionando sin haberlo corrido sería exactamente el
> problema que el producto viene a resolver.

---

## Advertencia que no es técnica

**Esto no reemplaza el control documental de una flota.**

El sistema ayuda a **no olvidar** vencimientos. No acredita que un documento
esté vigente ante un **órgano** fiscal. La vigencia de un permiso de
circulación, de un seguro o de una revisión técnica la acredita el documento
físico y la entidad que lo emitió, no esta aplicación.

Si el sistema dice que un permiso está vigente, quiere decir que **alguien
registró una fecha** y que nadie la corrigió. Si la persona que la registró se
equivocó, el sistema va a estar tan equivocado como la persona, y va a estarlo
con más seguridad, porque aparece en una pantalla y no en un papel.

Una flota no debería usar esto como respaldo de su control documental. Sirve
para no enterarse tarde, que es distinto y también sirve.

---

## Qué problema resuelve

El dueño de la flota lo describió así: un vehículo tiene el permiso de
circulación vencido mañana y el chofer no lo sabe. Otro tiene el seguro
vencido y nadie se enteró hasta el accidente.

Los documentos vencen en silencio y el funcionamiento normal del negocio se
detiene de golpe. Por eso **esta aplicación gira alrededor de los vencimientos,
no de las reparaciones**. Las mantenciones están, pero son un módulo de apoyo.

## Qué es y qué no es

**Es** software original escrito por Hefesto Studio: una aplicación propia,
multicliente, para documentar una flota y avisar antes de los vencimientos.

**No es** la aplicación de "Mantenciones" que ya existe. Aquella administra
servicios y contratos. Esta administra vehículos y documentos que vencen.

**No es** un sistema que declare la vigencia legal de nada. Ver la advertencia
de arriba.

## Los cuatro detalles de dominio que gobiernan el diseño

1. **El kilometraje no se escribe.** Viene del odómetro, de una mantención o de
   una carga de combustible, y cada lectura guarda **de dónde salió**. Un
   kilometraje que retrocede sin justificación se rechaza. Ver
   [`docs/02`](docs/02-MODELO-DE-DATOS.md).

2. **Hay dos vencimientos distintos.** El que **viene impreso** en el documento
   (un hecho) y el que **se renueva por inspección** (una periodicidad
   configurada). Se muestran por separado y nunca se suman.

3. **El plazo de anticipación es configurable**, y es la diferencia entre un
   sistema útil y uno que nadie mira. Los plazos por defecto son una decisión
   operativa de Hefesto, no una afirmación legal.

4. **Un vehículo puede estar en mantención y con documentos vencidos a la
   vez.** El sistema muestra los dos estados en dos ejes, sin que uno tape al
   otro. Es un caso que el dueño de la flota señaló, y hay una prueba dedicada a
   que siga funcionando.

## Sobre los plazos legales

**El sistema no inventa plazos legales, y hay una razón fuerte para eso.**

Un plazo escrito de memoria en un sistema de gestión es peor que un plazo
ausente: el operador confía en el número y deja de mirar el documento. El
catálogo de tipos de documento se carga **sin ningún plazo legal**, y las
columnas que lo guardarían quedan vacías a propósito.

Las fechas de vencimiento que usa el sistema son **las que vienen impresas en
cada documento**. Es el dato real y no necesita ser deducido.

Lo que el sistema sí hace es calcular una fecha **estimada** cuando hay una
regla de frecuencia configurada, y esa estimación se muestra marcada como tal.

> **Pendiente de confirmar por Hefesto:** los plazos legales aplicables a cada
> documento y a cada tipo de servicio, y quién los debe confirmar. Esa
> confirmación tiene que venir de un profesional del transporte, o del cliente.
> Está anotado como pendiente en
> [`docs/02`](docs/02-MODELO-DE-DATOS.md) y en
> [`docs/10`](docs/10-PRUEBAS-Y-ESTADO.md).

## Las alertas

- Se generan **desde una tarea programada**, no desde la vista. Una alerta que
  depende de que alguien mire una pantalla no es una alerta.
- Una alerta se genera **una sola vez por documento y período**. No es que el
  código se acuerde de no repetir: hay un **índice único en la base** sobre
  `(documento_id, periodo_clave)`, y reejecutar la tarea no puede duplicar nada.
- Al **renovar** un documento, la fecha nueva abre un período nuevo: los
  avisos futuros se vuelven a generar.
- Mientras `FLOTA_ALERTAS_ENVIO_ACTIVO` esté en `false`, las alertas se generan
  y se ven en el tablero, pero **no sale ningún correo**. El envío se activa
  solo después de probar la cadena completa de entrega.

## Stack

- **Backend:** Laravel 12 con PostgreSQL 16 y Row Level Security.
- **Frontend:** Nuxt 3 con SSR.
- **Caché y colas:** Redis, con versiones fijadas.
- **Identidad:** Authentik (OpenID Connect).
- **Despliegue:** Docker Compose con versiones fijadas en cada imagen.

## Estructura

```
flota-vehiculos/
├── README.md
├── .env.example          # Plantilla, sin ningún secreto real
├── docs/                 # 01 a 10
├── api/                  # Laravel: código, migraciones, semilla, pruebas
├── web/                  # Nuxt con SSR
└── deploy/               # Compose, Dockerfiles, configuración de PHP
```

## Documentación

| Documento | Qué resuelve |
|---|---|
| [01 Arquitectura](docs/01-ARQUITECTURA.md) | Cómo se arma el sistema y por qué así |
| [02 Modelo de datos](docs/02-MODELO-DE-DATOS.md) | Tablas, decisiones y los plazos que NO se inventan |
| [03 Multitenancy y roles](docs/03-MULTITENANCY-Y-ROLES.md) | Aislamiento entre flotas y quién puede hacer qué |
| [04 Instalación](docs/04-INSTALACION.md) | Levantar el stack |
| [05 Configuración](docs/05-CONFIGURACION.md) | Variables y ajustes que importan |
| [06 Primer vehículo](docs/06-PRIMER-VEHICULO.md) | Cargar una flota de verdad |
| [07 Seguridad](docs/07-SEGURIDAD.md) | Riesgos, con frases claras |
| [08 Respaldos](docs/08-RESPALDOS.md) | Qué se respalda y cómo se restaura |
| [09 API](docs/09-API.md) | Endpoints y ejemplos |
| [10 Pruebas y estado](docs/10-PRUEBAS-Y-ESTADO.md) | Qué se ejecutó y qué no |

## Inicio rápido

Requiere Docker y Docker Compose.

```bash
cp .env.example .env
# Completar APP_KEY, DB_PASSWORD, DB_MIGRATION_PASSWORD y REDIS_PASSWORD

docker compose -f deploy/compose.desarrollo.yaml --env-file .env up -d
docker compose -f deploy/compose.desarrollo.yaml exec api php artisan migrate --seed
docker compose -f deploy/compose.desarrollo.yaml exec api php artisan flota:verificar-aislamiento
```

El tablero queda en `http://localhost:3000`. La API responde en
`http://localhost:8000/api/salud`.

**Estos comandos están escritos y son los que se seguirían, pero no se han
ejecutado.** Ver [`docs/04-INSTALACION.md`](docs/04-INSTALACION.md).

## Licencia

Software propietario de Hefesto Studio. Laravel es MIT; Nuxt es MIT.