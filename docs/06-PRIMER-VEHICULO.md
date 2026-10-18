# 06 - Primer vehículo

Guía de carga inicial. El orden importa: sin catálogos no hay vehículo, y sin
documentos no hay nada que avisar.

> Ningún comando de esta guía se ejecutó. Ver
> [`10-PRUEBAS-Y-ESTADO.md`](10-PRUEBAS-Y-ESTADO.md).

## 1. Antes de cargar nada

### Decidir quién es "hoy"

Si el día que se carga el sistema ya hay documentos vencidos, **no los corrijas
para que la pantalla quede limpia**.

Carga la fecha real. Un tablero que parte en verde tiene mejor aspecto y es una
mentira: los documentos vencidos existen, y la primera vez que salgan a la calle
van a salir todos juntos.

Cargar la fecha real tiene además una ventaja: muestra qué parte de la flota
**ya está en problemas**, que es información que el dueño de la flota va a
querer ver en la primera reunión.

### Catálogo de documentos

```bash
php artisan db:seed --class=TipoDocumentoSeeder
```

Carga los tipos de documento **sin ningún plazo legal**. Ver
[`02-MODELO-DE-DATOS.md`](02-MODELO-DE-DATOS.md) sección 6.

Los tipos cargados por defecto: permiso de circulación, seguro obligatorio,
revisión técnica, padrón de fiscalización, licencia de conducir, certificado de
emisiones y certificado de habilitación para carga.

> Si la flota maneja un documento que no está en la lista, se agrega desde la
> administración. Y si sobra alguno, se deja inactivo.

## 2. Orden de carga recomendado

| Paso | Qué | Por qué en este orden |
|---|---|---|
| 1 | Tipos de servicio | El vehículo lo referencia y es obligatorio |
| 2 | Centros de costo | Opcional, pero mejor antes que nada |
| 3 | Talleres | Se pueden agregar después |
| 4 | Conductores | Necesarios para licencias y asignaciones |
| 5 | **Vehículos** | Requieren tipo de servicio |
| 6 | **Documentos** | El centro del producto |
| 7 | Lecturas de kilometraje | Necesitan vehículos |
| 8 | Mantenciones y asignaciones | Necesitan vehículos |
| 9 | Cargas de combustible | Necesitan vehículos |
| 10 | **Configuración de alertas** | Al final, cuando ya se conoce la flota |

## 3. Cargar un vehículo

```bash
POST /api/vehiculos
Authorization: Bearer <token>
X-Flota-Slug: <slug-de-la-flota>
```

```json
{
    "numero_interno": "CAM-01",
    "patente": "ABCD12",
    "marca": "Mercedes-Benz",
    "modelo": "Acco 815",
    "anio": 2021,
    "tipo_servicio_id": "<uuid>",
    "centro_costo_id": "<uuid>",
    "kilometraje_referencia": 148500
}
```

### La patente se normaliza sola

Da lo que dé: `"ABCD12"`, `"AB CD 12"`, `"AB·CD-12"` o `"abcd-12"` terminan
guardadas **igual**.

Eso no es un detalle: si quedaran distintas, la misma camioneta aparecería
cuatro veces y su aviso de vencimiento se mandaría cuatro veces.

Si la patente no tiene formato reconocible, el sistema la rechaza con un mensaje
que dice qué espera.

### Sobre `kilometraje_referencia`

Es el odómetro **de hoy**, al dar de alta el vehículo. No es un campo que se
mantenga al día: el kilometraje real sale de las lecturas.

## 4. Cargar los documentos

Este es el paso que importa.

```bash
POST /api/documentos
```

```json
{
    "vehiculo_id": "<uuid>",
    "tipo_documento_id": "<uuid del permiso de circulación>",
    "numero": "PC-2026-0001",
    "fecha_emision": "2026-01-10",
    "fecha_vencimiento": "2027-01-10"
}
```

### La fecha que va aquí es la que está IMPRESA en el documento

No una fecha calculada, no una estimada, no "más o menos un año". **La del
papel.**

El sistema no tiene ninguna lógica que genere esa fecha a partir de una
periodicidad, y esa es una decisión deliberada: un plazo legal escrito de
memoria en un sistema de gestión hace que el operador confíe en el número y
deje de mirar el documento físico.

### Documentos del conductor

Para la licencia de conducir, se cambia `vehiculo_id` por `conductor_id`:

```json
{
    "conductor_id": "<uuid>",
    "tipo_documento_id": "<uuid de licencia de conducir>",
    "fecha_vencimiento": "2028-03-01"
}
```

**Exactamente uno** de los dos. Si se mandan los dos, la base lo rechaza.

### Documentos sin vencimiento

Se puede dejar `fecha_vencimiento` en `null`. El sistema no avisa de un documento
sin fecha, y lo cuenta aparte en el panel como dato de carga incompleta.

Es preferible dejarlo vacío que inventarle una fecha.

## 5. Cargar el kilometraje inicial

```bash
POST /api/kilometraje
```

```json
{
    "vehiculo_id": "<uuid>",
    "km": 148500,
    "fuente": "odometro",
    "fecha": "2026-10-17"
}
```

### La fuente no es un detalle

| `fuente` | Cuándo usarla |
|---|---|
| `odometro` | Lectura directa del tablero |
| `mantencion` | Kilometraje que informó el taller |
| `carga_combustible` | Odómetro leído al cargar |
| `ajuste` | Corrección, y exige `motivo_ajuste` |

Un kilometraje menor que el último **se rechaza**, salvo que se declare ajuste
con motivo y con autor.

Es tentador "corregir" un error de tipeo borrando la lectura y volviendo a
cargar. No: se registra el ajuste. Un odómetro que se lee mal se corrige con un
ajuste que queda anotado, no borrando el número anterior. La diferencia entre
las dos versiones es lo que permite entender qué pasó con ese vehículo.

## 6. Cargar las mantenciones

```bash
POST /api/mantenciones
```

```json
{
    "vehiculo_id": "<uuid>",
    "descripcion": "Cambio de aceite y filtros",
    "tipo": "kilometraje",
    "km_programado": 150000,
    "estado": "programada"
}
```

El `tipo` decide qué se exige:

| `tipo` | Campo obligatorio |
|---|---|
| `kilometraje` | `km_programado` |
| `fecha` | `fecha_programada` |
| `mixto` | Al menos uno de los dos |

Una mantención en proceso **usa el vehículo**: al cargarla como `en_proceso`, el
estado operativo del vehículo pasa a "en mantención", y el tablero lo muestra
junto al estado documental.

Un vehículo no puede tener dos mantenciones en proceso a la vez. La base lo
impide.

## 7. Configurar las alertas

```bash
POST /api/configuracion-alertas
```

```json
{
    "tipo_documento_id": null,
    "umbral_dias": 30,
    "dias_insistencia_vencido": 7,
    "canal": "correo",
    "destinatarios": ["operaciones@empresa.cl"]
}
```

Esto es una decisión del cliente, no de Hefesto: **¿cuánto tiempo necesita para
conseguir el documento renovado?**

Para transporte con plazos más largos, 60 días suele ser necesario. Para una
flota urbana con revisión técnica al día, 30 basta.

`destinatarios` vacío significa "a todos los administradores de la flota".

## 8. Probar que todo funciona

```bash
# 1. Generar los avisos.
php artisan flota:generar-alertas --tenant=<slug>

# 2. Ver el tablero: http://localhost:3000
#    Tiene que mostrar los vencidos primero y la fecha de la última revisión.

# 3. Reejecutar. Tiene que dar CERO alertas nuevas.
php artisan flota:generar-alertas --tenant=<slug>

# 4. El aislamiento tiene que estar activo.
php artisan flota:verificar-aislamiento
```

El paso 3 es el que importa. Si genera alertas nuevas, hay un problema, y no hay
que seguir hasta entenderlo.

## 9. Activar el envío de correos

Solo después de probar el tablero.

```bash
# 1. Verificar SMTP con un mensaje de prueba.
php artisan tinker
>>> Mail::raw('prueba', fn ($m) => $m->to('destino@ejemplo.cl')->subject('Prueba'));

# 2. Activar.
FLOTA_ALERTAS_ENVIO_ACTIVO=true

# 3. Reiniciar para que recoja la variable.
docker compose -f deploy/compose.desarrollo.yaml restart api worker scheduler

# 4. Comprobar que la cola está drenando.
docker compose -f deploy/compose.desarrollo.yaml logs -f worker
```

## 10. Lista de verificación antes de entregar

- [ ] Todos los documentos con fecha real del papel
- [ ] Ningún documento cargado con una fecha inventada
- [ ] Los vencidos **dejados como vencidos**
- [ ] Kilometraje declarado con su fuente
- [ ] Umbrales de aviso acordados con el cliente
- [ ] `flota:verificar-aislamiento` sin fallos
- [ ] Segunda ejecución de `flota:generar-alertas` con cero nuevas
- [ ] Tablero mostrando los dos estados de cada vehículo
- [ ] Correo de prueba entregado
- [ ] **Confirmación de los plazos legales con un profesional del transporte o
      con el cliente** (pendiente, ver [`10`](10-PRUEBAS-Y-ESTADO.md))