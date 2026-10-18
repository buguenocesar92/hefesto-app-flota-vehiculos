# 05 - Configuración

## 1. Variables de entorno

La plantilla completa está en [`.env.example`](../.env.example), sin ningún valor
real. Lo que sigue es lo que **importa** y no es obvio.

### Base de datos

| Variable | Por qué importa |
|---|---|
| `DB_USERNAME` | Rol de **aplicación**. No puede ser superusuario ni tener `BYPASSRLS`. |
| `DB_MIGRATION_USERNAME` | Rol de migraciones. Tiene más privilegios, pero tampoco debería ser superusuario. |

La razón del rol sin privilegios está en
[`03-MULTITENANCY-Y-ROLES.md`](03-MULTITENANCY-Y-ROLES.md): con superusuario,
PostgreSQL ignora las políticas de RLS y el aislamiento entre flotas desaparece
**sin error visible**.

### Redis

Redis no es un caché opcional aquí. Sostiene:

- el candado `withoutOverlapping` de la tarea programada,
- `onOneServer()`, que evita que dos réplicas generen los mismos avisos.

Si Redis cae, la tarea programada se bloquea. Es un punto único de falla
**asumido a conciencia**, porque la alternativa (generar avisos sin candado)
duplica los avisos, y los avisos duplicados son justamente lo que hace que un
operador deje de mirarlos.

### Vencimientos y alertas

| Variable | Por defecto | Qué hace |
|---|---|---|
| `FLOTA_ZONA_HORARIA` | `America/Santiago` | Zona para calcular los días que faltan. La de cada **tenant** manda sobre esta. |
| `FLOTA_PROGRAMACION_ALERTAS` | `0 10 * * *` | Cron de la generación. `0 10 * * *` es 06:00 hora de Chile. |
| `FLOTA_CANDADO_ALERTAS_MINUTOS` | `30` | Ventana del candado antes de que otra ejecución pueda entrar. |
| `FLOTA_ALERTAS_ENVIO_ACTIVO` | `false` | Si se envían correos o solo se generan avisos. |
| `FLOTA_LITROS_MINIMOS_RENDIMIENTO` | `5` | Por debajo de esto el rendimiento no se muestra. |
| `FLOTA_POR_PAGINA` | `25` | Tamaño de página de los listados. |

### Por qué 06:00 y no medianoche

La generación de avisos a las 06:00 llega cuando el operador ya está trabajando
y todavía tiene tiempo de gestionar el documento **antes de que el vehículo
salga**. A las 23:59, el aviso llega a una pantalla que nadie va a mirar hasta
las ocho de la mañana, y para entonces el vehículo ya salió.

### Por qué el envío está apagado por defecto

`FLOTA_ALERTAS_ENVIO_ACTIVO=false` hace que el generador **cree y guarde** las
alertas, y **no despache nada**. Los avisos se ven en el tablero.

Es lo correcto para el primer despliegue: activar el envío sin haber probado la
cadena completa (SMTP, remitente, cola, worker) significa descubrir un problema
cuando un cliente ya está esperando un correo que nunca llega.

Orden recomendado:

1. Desplegar con el envío apagado.
2. Cargar datos reales.
3. Correr `flota:generar-alertas` y revisar el tablero.
4. Verificar SMTP con un mensaje de prueba.
5. Activar el envío.

## 2. Los plazos de aviso

### Qué son y qué no son

**Son plazos de AVISO.** "Con 30 días" significa "avísame un mes antes", no "la
ley dice que dura 30 días".

**No son plazos legales.** El plazo legal de cada documento vive en
`tipos_documento.plazo_legal_dias`, y **arranca vacío**. Ver
[`02-MODELO-DE-DATOS.md`](02-MODELO-DE-DATOS.md) sección 6.

### Configuración por tipo de documento

`configuraciones_alerta` permite dos niveles:

| `tipo_documento_id` | Significado |
|---|---|
| `NULL` | El umbral aplica a **todos** los tipos de documento de la flota |
| Con valor | El umbral aplica solo a ese tipo |

Se combinan: un cliente puede querer "avísame 30 días antes de todo" y además
"avísame 7 días antes del permiso de circulación". Gana el más cercano.

```bash
# Aviso global a 60, 30, 15, 7 y 1 días.
POST /api/configuracion-alertas
{
    "tipo_documento_id": null,
    "umbral_dias": 60,
    "dias_insistencia_vencido": 7,
    "canal": "correo"
}
```

### Insistencia tras el vencimiento

`dias_insistencia_vencido` genera **una alerta más** cuando el documento lleva
ese número de días vencido.

Por defecto es `0`: un aviso de vencimiento y nada más. Cada insistencia tiene
**su propia clave de período** (`insistencia:7`, `insistencia:14`), así que no
se pisan entre sí, y subir el valor de 7 a 14 no bloquea el aviso nuevo.

Los valores por defecto de la semilla (0, 3 y 7 días según el umbral) son una
decisión operativa de Hefesto. **No son plazos legales** y se pueden cambiar por
flota.

## 3. Autenticación

La identidad la resuelve **Authentik** por OpenID Connect. Esta aplicación no
implementa el login: recibe un token.

### Cómo llega el token

1. El navegador se autentica en Authentik.
2. La aplicación recibe el token de sesión.
3. Lo guarda en una cookie **httpOnly** (`flota_token`).
4. Nuxt lo reenvía a la API desde el **servidor**, en la cabecera
   `Authorization`.
5. La API busca `usuarios.token_hash`, que es el **SHA-256** del token.

El token **nunca llega al código del navegador**. El proxy del servidor es el
único que lo usa.

### Por qué el hash y no el token

Una filtración de la base de datos no debe entregar sesiones válidas. Con un
hash SHA-256, un atacante que consiga leer la tabla tiene algo que no puede
usar para autenticarse.

### `X-Flota-Slug`

La API resuelve la flota por el slug antes de autenticar, porque necesita un
tenant para leer la tabla de usuarios.

**El slug no es una credencial.** Es público y no da acceso a nada por sí solo:
la autenticación sigue exigiendo el token, y se verifica que el usuario
pertenezca a esa flota.

### Restricción por dominio de correo

```bash
FLOTA_DOMINIOS_PERMITIDOS=empresa.cl,transportista.cl
```

Vacío significa "sin restricción". Cuando está configurado, solo entran
usuarios cuyo correo pertenezca a alguno de esos dominios.

> **Pendiente:** la página de login (intercambio del código de autorización con
> Authentik) **no está implementada** en el código entregado. El middleware está
> listo y probado por lectura, pero falta construir el flujo completo.

## 4. Zona horaria

Los días que faltan se calculan por **días calendario completos**, con la zona
horaria de la **flota** (`tenants.zona_horaria`), no la del contenedor.

La diferencia no es teórica. Si el cálculo corriera en UTC, en horario de verano
chileno un documento que vence "mañana" contaría **un día de más** y el aviso
llegaría tarde. Una alerta que llega tarde no sirve: es exactamente el problema
que el producto vino a resolver.

Regla del cálculo:

```
días restantes = hoy (00:00 hora de Chile) → fecha de vencimiento (00:00)
```

Un documento que vence hoy tiene **cero** días restantes, no menos uno. Esa
diferencia separa el mensaje "vence hoy" de "venció ayer", y es el mensaje que
después se manda al conductor. Hay una prueba dedicada:
`tests/Unit/CalculoDeDiasRestantesTest.php`.

## 5. Rendimiento de combustible

No se guarda: **se calcula** sobre las cargas consecutivas del vehículo.

```php
$rendimiento = $calculadora->promedioPorVehiculo($vehiculo);
```

- Con **una sola carga** no hay tramo, y el sistema devuelve `null` con una
  explicación. No inventa un número.
- Con **menos de `FLOTA_LITROS_MINIMOS_RENDIMIENTO` litros**, no muestra
  rendimiento: con dos litros, una suma de redondeo ya cambia el número.
- El promedio **pondera por litros**, no por cantidad de cargas. Promediar
  "20 km/l" con "6 km/l" dando el mismo peso a las dos produce una cifra que no
  corresponde a ningún periodo real.

## 6. Datos que el sistema no guarda a propósito

| Dato | Por qué no se guarda |
|---|---|
| `kilometraje_actual` en el vehículo | Se deriva de las lecturas. Dos fuentes de verdad se desincronizan. |
| `estado` temporal en el documento | Se calcula desde la fecha. Ver [`01`](01-ARQUITECTURA.md) 2.1. |
| `rendimiento` en la carga | Se calcula. Si se guardara, dependería de que alguien lo escribiera bien. |
| `estado` en el vehículo | Se calcula. Un estado guardado queda viejo el día que el documento vence. |

Esta lista no es austeridad. Cada fila es un bug que alguien iba a reportar más
tarde.

## 7. Ajustes recomendados para producción

|Ajuste | Motivo |
|---|---|
| `APP_DEBUG=false` | Nunca enviar trazas al cliente. |
| `LOG_LEVEL=info` | Suficiente para operar, sin llenar el disco. |
| Puertos de PostgreSQL y Redis solo en loopback | No están publicados para nada en la red. |
| TLS en el proxy inverso | Los tokens viajan en cookies y cabeceras. |
| `opcache.validate_timestamps=0` | Más rápido, pero **obliga a reiniciar PHP-FPM en cada despliegue**. |