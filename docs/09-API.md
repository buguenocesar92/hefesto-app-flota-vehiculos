# 09 - API

> **Nada de esta API se ejecutó ni se probó contra un servidor real.** Los
> endpoints están escritos siguiendo el enrutado de `routes/api.php`, y los
> ejemplos son los que la aplicación produce por diseño. Ver
> [`10-PRUEBAS-Y-ESTADO.md`](10-PRUEBAS-Y-ESTADO.md).

## 1. Convenciones

- **Formato:** JSON en las dos direcciones.
- **Prefijo:** `/api`.
- **Autenticación:** `Authorization: Bearer <token>`.
- **Contexto de flota:** cabecera `X-Flota-Slug`.

```bash
curl -s https://flota.ejemplo.cl/api/documentos \
    -H "Authorization: Bearer <token>" \
    -H "X-Flota-Slug: flota-demo"
```

### El slug no es una credencial

Es público. La API lo usa para resolver el contexto antes de autenticar, porque
`usuarios` tiene RLS y sin tenant no se puede leer. La autenticación sigue
exigiendo el token, y se verifica que el usuario pertenezca a esa flota.

### Errores

```json
{
    "error": {
        "codigo": 422,
        "mensaje": "La patente \"XYZ\" no tiene un formato chileno reconocible."
    }
}
```

Nunca se devuelve una traza. Las excepciones internas se registran en el log.

| Código | Cuándo |
|---|---|
| `401` | Sin token, token inválido, usuario desactivado o desactivada. |
| `403` | Rol sin permiso para esa operación. |
| `404` | No existe **o** es de otra flota. |
| `422` | Los datos no pasan la validación. |
| `500` | Error interno. Ya está registrado. |

Un `403` confirmaría que el recurso existe, así que el aislamiento entre flotas
responde `404`: para la otra flota, el recurso no existe.

## 2. Estado del servicio

### `GET /api/salud`

Sin autenticación. **No dice nada de los datos de nadie**: ni cuántos vehículos
hay, ni nombres. Un monitor de disponibilidad no necesita credenciales, y este
no expone nada útil.

```json
{
    "servicio": "flota-vehiculos",
    "estado": "ok",
    "version": "1.0.0"
}
```

## 3. Tablero

### `GET /api/panel/resumen`

```json
{
    "vehiculos_activos": 6,
    "documentos_vigentes": 9,
    "documentos": {
        "vencido": 3,
        "por_vencer": 4,
        "vigente": 1,
        "sin_fecha": 1
    },
    "vehiculos_en_taller_y_vencidos": 1,
    "mant_en_proceso": 1,
    "alertas_pendientes": 5,
    "ultima_generacion_alertas": "2026-10-17T10:00:00+03:00",
    "fecha_referencia": "2026-10-17"
}
```

`vehiculos_en_taller_y_vencidos` va **separado** a propósito: es el caso que el
dueño de la flota señaló, y sumarlo con el total de vencidos lo escondería.

### `GET /api/panel/vencimientos?dias=90`

Documentos ordenados por **urgencia**: los vencidos primero, aunque su número de
días sea negativo.

```json
{
    "datos": [
        {
            "id": "uuid",
            "tipo_documento": "Seguro obligatorio",
            "vehiculo_patente": "UVWX-23",
            "conductor": null,
            "numero": "POL-550019",
            "fecha_emision": "2023-09-16",
            "fecha_vencimiento": "2026-09-26",
            "fecha_efectiva": "2026-09-26",
            "origen_vencimiento": "documento",
            "estimada": false,
            "dias_restantes": -21,
            "estado": "vencido",
            "estado_etiqueta": "Vencido"
        }
    ],
    "total": 1,
    "dias_consultados": 90,
    "calculado_en": "2026-10-17T11:04:22-03:00"
}
```

**`origen_vencimiento` y `estimada` son los campos importantes.** Con
`estimada: true`, la fecha viene de una regla de frecuencia configurada, no de
una fecha impresa. La interfaz tiene que mostrarlo distinto.

### `GET /api/panel/alertas-generadas`

```json
{
    "ultima_generacion": "2026-10-17T10:00:00+03:00",
    "nunca_ejecutada": false,
    "programacion": "0 10 * * *",
    "zona_horaria": "America/Santiago"
}
```

Un tablero todo en verde **sin** este dato no permite saber si los datos son de
ahora o de hace tres días.

## 4. Vehículos

| Método | Ruta | Rol |
|---|---|---|
| `GET` | `/api/vehiculos` | cualquiera |
| `POST` | `/api/vehiculos` | operador |
| `GET` | `/api/vehiculos/{id}` | cualquiera |
| `PUT` | `/api/vehiculos/{id}` | operador |
| `DELETE` | `/api/vehiculos/{id}` | administrador |

### `POST /api/vehiculos`

```json
{
    "numero_interno": "CAM-01",
    "patente": "ABCD12",
    "marca": "Mercedes-Benz",
    "modelo": "Acco 815",
    "anio": 2021,
    "tipo_servicio_id": "uuid",
    "centro_costo_id": "uuid"
}
```

La patente se **normaliza** antes de validar. Da lo que dé, queda igual.

### `GET /api/vehiculos/{id}`

Cada vehículo trae **los dos estados**:

```json
{
    "id": "uuid",
    "patente": "UVWX-23",
    "kilometraje_actual": 265100,
    "estado_calculado": {
        "operativo": "en_mantencion",
        "operativo_etiqueta": "En mantención",
        "documental": "vencido",
        "documental_etiqueta": "Vencido",
        "hay_algo_critico": true,
        "en_taller_y_vencido": true,
        "alertas_pendientes": 2
    },
    "documentos_estado": [
        {
            "id": "uuid",
            "tipo": "Seguro obligatorio",
            "fecha_vencimiento": "2026-09-26",
            "dias_restantes": -21,
            "estado": "vencido"
        }
    ]
}
```

Y el detalle de cada documento viaja con su estado propio: el resumen dice
"vencido", y la lista dice **cuál** de los dos.

### `DELETE` retira, no borra

Marca `activo = false` y conserva todo el historial. Un vehículo que se borró
de la base tiene documentos que hay que poder responderle a un fiscal dos años
después.

## 5. Documentos

| Método | Ruta | Rol |
|---|---|---|
| `GET` | `/api/documentos` | cualquiera |
| `GET` | `/api/documentos/vencimientos` | cualquiera |
| `POST` | `/api/documentos` | operador |
| `GET` | `/api/documento/{id}` | cualquiera |
| `PUT` | `/api/documento/{id}` | operador |
| `DELETE` | `/api/documento/{id}` | administrador |

### `POST /api/documentos`

```json
{
    "vehiculo_id": "uuid",
    "tipo_documento_id": "uuid",
    "numero": "PC-2026-0001",
    "fecha_emision": "2026-01-10",
    "fecha_vencimiento": "2027-01-10"
}
```

**La fecha es la que está impresa en el documento.** El sistema no la calcula.

Exactamente uno de `vehiculo_id` o `conductor_id`. Si se mandan los dos, o
ninguno, la base lo rechaza.

**No existe ningún campo de plazo legal.** Ver
[`02-MODELO-DE-DATOS.md`](02-MODELO-DE-DATOS.md) sección 6.

### `PUT`: la renovación

Actualizar la fecha de un documento vigente es la renovación. El documento
anterior de ese tipo pasa a `historico`, y el nuevo ciclo de avisos se abre
sola, porque la clave de período incluye la fecha.

`DELETE` **anula**: el registro queda con estado `anulado` y sale del cálculo de
vencimientos. El historial permanece.

## 6. Alertas

| Método | Ruta |
|---|---|
| `GET` | `/api/alertas?estado=pendiente` |
| `GET` | `/api/alertas/{id}` |
| `POST` | `/api/alertas/{id}/reintentar` |

Solo lista avisos que **ya existen**. No calcula ni crea.

### El reintento crea una alerta nueva

El reintento **no** reutiliza la original: genera una nueva con una clave de
período distinta.

Si reutilizara la anterior, no habría forma de saber cuántos intentos hubo, y el
índice único dejaría de cumplir su función.

## 7. Kilometraje

| Método | Ruta |
|---|---|
| `GET` | `/api/kilometraje?vehiculo_id=uuid` |
| `POST` | `/api/kilometraje` |

### `POST /api/kilometraje`

```json
{
    "vehiculo_id": "uuid",
    "km": 148500,
    "fuente": "odometro",
    "fecha": "2026-10-17"
}
```

La fuente es obligatoria y admite: `odometro`, `mantencion`,
`carga_combustible`, `ajuste`.

Un kilometraje menor que el último se rechaza con `422`:

```json
{
    "error": {
        "codigo": 422,
        "mensaje": "El kilometraje indicado (40000) es menor que la última lectura (140000) del vehículo ABCD-12. Si la lectura es correcta, regístrela como ajuste con su motivo."
    }
}
```

Para registrarlo igual, con `fuente: "ajuste"` y `motivo_ajuste`.

## 8. Mantenciones, conductores, viajes

| Método | Ruta | Rol |
|---|---|---|
| `GET` `POST` | `/api/mantenciones` | lectura / operador |
| `GET` `PUT` | `/api/mantenciones/{id}` | lectura / operador |
| `GET` `POST` | `/api/conductores` | lectura / operador |
| `GET` | `/api/conductores/{id}` | lectura |
| `GET` `POST` | `/api/viajes` | lectura / operador |
| `PUT` | `/api/viajes/{id}` | operador |

El `tipo` de la mantención decide qué se exige: `kilometraje` pide
`km_programado`, `fecha` pide `fecha_programada`, `mixto` pide al menos uno.

Actualizar a `completada` **exige** `fecha_ejecucion`.

## 9. Combustible

| Método | Ruta |
|---|---|
| `GET` `POST` | `/api/cargas-combustible` |
| `GET` | `/api/combustible/rendimiento?vehiculo_id=uuid` |

Al registrar una carga, la API **también genera la lectura de kilometraje** con
fuente `carga_combustible`. Cargar es una de las tres maneras legítimas de saber
cuánto lleva el vehículo.

El total se recalcula desde litros y precio unitario. No se confía en lo que
venga en el formulario.

### El rendimiento

```json
{
    "vehiculo_id": "uuid",
    "patente": "EFGH-34",
    "calculado": true,
    "rendimiento": {
        "kilometros_recorridos": 200,
        "litros_cargados": 150,
        "kilometros_por_litro": 1.33,
        "litros_por_100_kilometros": 75.18,
        "costo_total": 163500,
        "cantidad_cargas": 2
    },
    "motivo_sin_datos": null
}
```

Si no hay datos suficientes:

```json
{
    "vehiculo_id": "uuid",
    "calculado": true,
    "rendimiento": null,
    "motivo_sin_datos": "Hacen falta al menos dos cargas de combustible del mismo vehículo para calcular el rendimiento."
}
```

Con `null` y una explicación, **no con un número inventado**.

## 10. Configuración de alertas

| Método | Ruta | Rol |
|---|---|---|
| `GET` | `/api/configuracion-alertas` | cualquiera |
| `POST` | `/api/configuracion-alertas` | administrador |
| `PUT` | `/api/configuracion-alertas/{id}` | administrador |
| `DELETE` | `/api/configuracion-alertas/{id}` | administrador |

```json
{
    "datos": [],
    "umbrales_por_defecto": [60, 30, 15, 7, 1],
    "nota": "Estos son plazos de aviso, no plazos legales. Los plazos legales los confirma Hefesto con un profesional del transporte o con el cliente."
}
```

La respuesta **incluye esa nota**, para que la interfaz no pueda presentar esos
números como si fueran legales.

## 11. Comandos de consola

```bash
# Generar alertas.
php artisan flota:generar-alertas
php artisan flota:generar-alertas --tenant=flota-demo
php artisan flota:generar-alertas --fecha=2026-10-17 --json

# Verificar el aislamiento. En el despliegue, siempre.
php artisan flota:verificar-aislamiento
```

La salida del generador incluye una línea que es la prueba operativa de que es
idempotente:

```
Alertas nuevas                      0
Alertas que ya existían             7
Sin novedades: todas las alertas de este período ya estaban generadas.
```