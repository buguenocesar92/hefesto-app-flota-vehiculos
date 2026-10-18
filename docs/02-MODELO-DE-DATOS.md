# 02 - Modelo de datos

## 1. Las tablas

| Tabla | Qué guarda | `tenant_id` | RLS |
|---|---|---|---|
| `tenants` | Las flotas | no | no (a propósito) |
| `usuarios` | Personas con acceso | sí | sí |
| `tipos_servicio` | Catálogo de servicios | sí | sí |
| `centros_costo` | Dónde se imputa el gasto | sí | sí |
| `talleres` | Quién ejecuta la mantención | sí | sí |
| `conductores` | Los conductores | sí | sí |
| `vehiculos` | La ficha del vehículo | sí | sí |
| `tipos_documento` | Catálogo **nacional** de documentos | **no** | **no** |
| `configuraciones_alerta` | Plazos de aviso de la flota | sí | sí |
| `documentos` | **El centro del producto** | sí | sí |
| `reglas_frecuencia_documento` | Vencimiento por inspección | sí | sí |
| `mantenciones` | Historial de mantenciones | sí | sí |
| `asignaciones` | Vehículo con conductor | sí | sí |
| `viajes` | Salidas registradas | sí | sí |
| `cargas_combustible` | Cargas de combustible | sí | sí |
| `lecturas_kilometraje` | Lecturas con su fuente | sí | sí |
| `alertas_documento` | Avisos generados | sí | sí |

## 2. Las dos decisiones sobre `tenants` y `tipos_documento`

Son las dos únicas tablas sin `tenant_id`, y las dos son decisiones
conscientes.

### `tenants` no lleva RLS

Se consulta **durante el login**, cuando todavía no hay sesión y por lo tanto no
existe `app.tenant_id` que poner en la conexión. Si tuviera política, el login
se rompería siempre.

Compensación: la vista `vw_tenants_publicos`, que expone **tres columnas**
(`slug`, `nombre`, `activo`) y solo de las flotas activas. No expone RUT, razón
social, correos ni teléfonos.

### `tipos_documento` es un catálogo global

El permiso de circulación, el seguro obligatorio, la revisión técnica y el padrón
de fiscalización son el **catálogo nacional**, no datos de una flota.

- Duplicarlos por cliente no agrega información, solo lugares donde
  desincronizarse.
- La tabla no contiene nada sensible: ni nombres de empresas, ni RUT, ni
  números de documento, ni correos. Que alguien la lea no revela nada de ninguna
  flota.
- Lo que **sí** es de cada flota vive en `configuraciones_alerta`: cuántos días
  antes quiere avisos, a quién, y por qué canal.

## 3. Los documentos: el centro del producto

### 3.1 Vencimiento por fecha y por inspección están SEPARADOS

Es el detalle de dominio más importante, y por eso son **dos tablas**.

| | Dónde vive | Qué es |
|---|---|---|
| Vencimiento por **fecha** | `documentos.fecha_vencimiento` | Un **hecho**: está impreso en el documento |
| Vencimiento por **inspección** | `reglas_frecuencia_documento` | Una **configuración**: alguien decidió una periodicidad |

Si el documento tiene fecha impresa, **manda la impresa**. Si no la tiene y hay
una regla de frecuencia **confirmada**, se usa la calculada, y el aviso sale
**marcado como estimado**.

Nunca se suman ni se mezclan. Un documento con las dos fechas muestra las dos,
con su origen.

### 3.2 El estado temporal NO se guarda

`documentos.estado` guarda solo lo administrativo: `vigente`, `anulado`,
`historico`.

"Vencido" y "por vencer" se **calculan**:

```php
EstadoTemporalDocumento::calcular($fecha, $diasDeAnticipacion, $estado)
```

La razón está en [`01`](01-ARQUITECTURA.md) sección 2.1: un estado guardado es
una segunda fuente de verdad que se desincroniza en el primer cambio de fecha.

### 3.3 Un solo documento, dos sujetos posibles

La licencia de conducir es del conductor; el permiso de circulación es del
vehículo. En vez de dos tablas iguales con el código duplicado, hay una sola con
una restricción que obliga a informar **exactamente uno**:

```sql
CHECK (
    (vehiculo_id IS NOT NULL AND conductor_id IS NULL)
    OR (vehiculo_id IS NULL AND conductor_id IS NOT NULL)
)
```

### 3.4 No puede haber dos documentos vigentes del mismo tipo

```sql
CREATE UNIQUE INDEX documentos_un_vigente_por_tipo
ON documentos (tenant_id, tipo_documento_id, vehiculo_id, conductor_id)
WHERE estado = 'vigente';
```

No se puede cargar un segundo permiso de circulación vigente para la misma
camioneta. Si se intenta, la base lo rechaza. **No es una validación que alguien
pueda saltarse olvidándose de reescribir el formulario.**

### 3.5 El estado "histórico" y las renovaciones

Cuando se renueva un documento, se actualiza la fecha del mismo registro y el
anterior de ese tipo pasa a `historico`. Así se conserva la cadena completa de
documentos del vehículo, que es lo que hay que poder mostrarle a un fiscal.

## 4. El kilometraje: la fuente es el dato

`vehiculos` **no tiene columna `kilometraje_actual`**. El kilometraje se deriva de
`lecturas_kilometraje`, y cada lectura declara su origen:

| `fuente` | De dónde sale |
|---|---|
| `odometro` | Lectura del tablero |
| `mantencion` | Kilometraje que informó el taller al entregar |
| `carga_combustible` | Odómetro leído al cargar |
| `ajuste` | Corrección justificada: exige motivo y autor |

### Dos restricciones que la base impone

**1. Un ajuste sin motivo no se puede insertar.**

```sql
CHECK (
    (fuente = 'ajuste' AND motivo_ajuste IS NOT NULL AND btrim(motivo_ajuste) <> '')
    OR (fuente <> 'ajuste')
)
```

Un "ajuste" sin explicación es exactamente el campo libre que este diseño elimina.

**2. Un ajuste exige autor.** Sin `usuario_id`, un cambio de kilometraje queda
sin responsable.

Y en la capa de aplicación, `RegistroDeKilometraje` rechaza un kilometraje menor
que el último salvo que se declare ajuste. Un `40.000` donde iba `140.000` rompe
en silencio el plan de mantenciones por distancia y el cálculo de rendimiento; con
la regla, el error se vuelve visible.

### Encadenamiento

Cada lectura guarda `lectura_anterior_id`. El diferencial entre dos lecturas
consecutivas es el tramo recorrido, y es lo que alimenta el rendimiento.

## 5. Las alertas: una por documento y período

### La clave de período

```
v:{fecha_vencimiento}:{tipo}:{detalle}
```

| Ejemplo | Cuándo |
|---|---|
| `v:2026-05-20:umbral:30` | A 30 días del vencimiento |
| `v:2026-05-20:umbral:15` | A 15 días |
| `v:2026-05-20:vencido` | Ya vencido |
| `v:2026-05-20:insistencia:7` | Vencido, con insistencia configurada a 7 días |

Es **determinista**: la misma fecha y el mismo tipo producen siempre la misma
cadena. Por eso la tarea programada se puede reejecutar sin miedo.

Y **incluye la fecha**, que es lo que resuelve la renovación: al renovar el
documento, la fecha nueva abre un período nuevo y los avisos se vuelven a
generar. Sin ese componente, renovar el permiso dejaría al vehículo mudo para
siempre, bloqueado por los avisos del ciclo anterior.

### El índice único

```sql
CREATE UNIQUE INDEX alertas_documento_unica_por_periodo
ON alertas_documento (documento_id, periodo_clave);
```

Es **la garantía real**. Ver [`10-PRUEBAS-Y-ESTADO.md`](10-PRUEBAS-Y-ESTADO.md).

### Los días que faltan son una foto

`dias_restantes` se guarda como estaba **en el momento de generar el aviso**. Un
aviso de hace un mes sigue diciendo "vencía en 7 días", que es lo que el operador
necesita ver, en vez de recalcular una cifra que ya no significa nada.

## 6. Plazos legales: lo que el sistema NO inventa

Esta es la sección más importante del documento.

### Las columnas existen y van vacías

`tipos_documento` tiene:

```
plazo_legal_dias             NULL
plazo_legal_fuente           NULL
plazo_legal_confirmado_por   NULL
plazo_legal_confirmado_at    NULL
```

La semilla las carga **vacías**, y `TipoDocumento::tienePlazoLegalConfirmado()`
devuelve `false` para todas.

### Por qué están vacías

En el entorno donde se escribió este código **no se verificó la normativa
chilena aplicable**. Y un plazo legal escrito de memoria es peor que un plazo
ausente: el operador confía en el número y deja de mirar el documento físico.

El sistema funciona igual sin ellos. La fecha que usa para avisar es **la que
viene impresa en el documento**, que es el dato real y no necesita deducirse.

### Las reglas de frecuencia tienen el mismo candado

Una `periodicidad_dias` en `reglas_frecuencia_documento` **no entra en el cálculo
de alertas** hasta que exista `confirmado_por`, `fuente_plazo` y `confirmado_at`
(`ReglaFrecuenciaDocumento::estaConfirmada()`).

Se puede cargar una periodicidad de borrador mientras se conversa con el
cliente. No produce avisos hasta que alguien se haga cargo de ella.

### Cuándo se llenan

**Hefesto debe confirmarlos con un profesional del transporte, o con el
cliente**, y cargar la fuente. Hasta entonces, el sistema opera con las fechas
impresas y nada más.

> **PENDIENTE.** No verificado contra normativa en este entorno. Ver
> [`10-PRUEBAS-Y-ESTADO.md`](10-PRUEBAS-Y-ESTADO.md).

## 7. Restricciones de negocio en la base

Estas reglas no dependen del código de aplicación. Se sostienen aunque alguien
escriba directamente por SQL.

| Restricción | Por qué |
|---|---|
| Un documento vigente por tipo y sujeto | No hay dos permisos de circulación para la misma camioneta |
| Exactamente un sujeto por documento | Un documento es del vehículo o del conductor |
| Vencimiento posterior a la emisión | Un documento no vence antes de emitirse |
| Un ajuste exige motivo y autor | No hay correcciones sin responsable |
| Un tipo de mantenimiento mide en algo | Una periodicidad vacía no ordena nada |
| Una mantención completada tiene fecha | Sin fecha no hay reporte posible |
| Una mantención en proceso por vehículo | No se está en dos talleres a la vez |
| Una asignación abierta por vehículo | Un vehículo no está con dos conductores |
| Una asignación abierta por conductor | Un conductor no está en dos vehículos |
| Litros y kilometraje positivos | Una carga de cero litros no es una carga |
| Una regla de frecuencia mide en algo | Una periodicidad sin unidad no dice nada |

## 8. Migraciones

| Archivo | Crea |
|---|---|
| `2026_03_01_000001_crear_tabla_tenants.php` | `tenants` |
| `2026_03_01_000002_crear_tabla_usuarios.php` | `usuarios` |
| `2026_03_01_000003_crear_catalogos_de_administracion.php` | `tipos_servicio`, `centros_costo`, `talleres` |
| `2026_03_01_000004_crear_tabla_conductores.php` | `conductores` |
| `2026_03_01_000005_crear_tabla_vehiculos.php` | `vehiculos` |
| `2026_03_01_000006_crear_tipos_documento_y_configuracion_alertas.php` | `tipos_documento`, `configuraciones_alerta` |
| `2026_03_01_000007_crear_tabla_documentos.php` | `documentos` |
| `2026_03_01_000008_crear_tabla_reglas_frecuencia_documento.php` | `reglas_frecuencia_documento` |
| `2026_03_01_000009_crear_tabla_mantenciones.php` | `mantenciones` |
| `2026_03_01_000010_crear_tabla_asignaciones_y_viajes.php` | `asignaciones`, `viajes` |
| `2026_03_01_000011_crear_tabla_cargas_combustible.php` | `cargas_combustible` |
| `2026_03_01_000012_crear_tabla_lecturas_kilometraje.php` | `lecturas_kilometraje` |
| `2026_03_01_000013_crear_tabla_alertas_documento.php` | `alertas_documento` |
| `2026_03_01_000014_activar_row_level_security.php` | `app_tenant_id()` y todas las políticas |
| `2026_03_01_000015_crear_vista_tenants_publicos.php` | `vw_tenants_publicos` |

El orden importa: `lecturas_kilometraje` declara claves foráneas a
`mantenciones`, `viajes` y `cargas_combustible`, así que se crea después que
ellas.

La de RLS va **última**: si una tabla nueva no estuviera en su lista, la
migración falla con un error explícito en vez de dejarla sin protección.