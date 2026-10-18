# 08 - Respaldos

## 1. Qué se respalda

| Dato | Por qué |
|---|---|
| Documentos de vehículos y conductores | Es el corazón del negocio del cliente |
| Historial de mantenciones y viajes | Se consulta después de un incidente |
| Lecturas de kilometraje | Sin ellas no se puede reconstruir nada |
| Configuración de alertas | Es la decisión acordada con el cliente |
| Alertas generadas | Evidencia de que se avisó y cuándo |

## 2. Qué NO está en los respaldos

| No se respalda | Por qué |
|---|---|
| **Los documentos escaneados** | No se implementó el almacenamiento de archivos en esta versión |
| La base de datos de Authentik | Es de Hefesto y se respalda una sola vez para todas las apps |
| Redis | Es caché y cola. Se reconstruye |

Lo primero es una limitación real y hay que decirla: el sistema **no guarda el
PDF ni la foto del permiso de circulación**. Guarda los datos del documento. El
respaldo del cliente tiene que incluir sus propios archivos, que siguen siendo
suyos.

## 3. Respaldo de la base

```bash
docker compose -f deploy/compose.produccion.yaml exec -T postgres \
    pg_dump -U <rol_migracion> -d <base> --format=custom --file=/tmp/flota.dump

docker compose -f deploy/compose.produccion.yaml cp \
    postgres:/tmp/flota.dump ./respaldos/flota-$(date +%F).dump
```

### Opciones que importan

| Opción | Por qué |
|---|---|
| `--format=custom` | Comprimido y restaurable con `pg_restore`. |
| `--no-owner` | Para que se pueda restaurar en un servidor con otros usuarios. |
| `--clean --if-exists` | Al restaurar, que no fallen objetos ya existentes. |

### Frecuencia

**Diaria.** Los documentos vencen todos los días, así que la pérdida de un día
de alertas es la pérdida de un día de avisos.

## 4. Restauración

```bash
# 1. Base vacía (¡esto borra todo!).
docker compose -f deploy/compose.produccion.yaml exec postgres \
    dropdb -U <rol_migracion> <base>
docker compose -f deploy/compose.produccion.yaml exec postgres \
    createdb -U <rol_migracion> <base>

# 2. Restaurar.
docker compose -f deploy/compose.produccion.yaml cp ./respaldos/flota-AAAA-MM-DD.dump postgres:/tmp/
docker compose -f deploy/compose.produccion.yaml exec postgres \
    pg_restore -U <rol_migracion> -d <base> --clean --if-exists /tmp/flota.dump

# 3. VERIFICAR EL AISLAMIENTO. Este paso no es opcional.
docker compose -f deploy/compose.produccion.yaml exec api \
    php artisan flota:verificar-aislamiento
```

El paso 3 importa: una restauración puede cambiar el rol de la aplicación, y un
rol con privilegios de más deja el aislamiento desactivado **sin error visible**.

## 5. Restauración probada o no

> **No se probó ninguna restauración.** No había PostgreSQL ni Docker en el
> entorno donde se escribió el sistema.

Un respaldo que nunca se restauró **no es un respaldo**: es una archivo con
confianza. La primera vez que se pruebe va a ser en el peor momento.

**Antes de entregar el sistema al cliente hay que restaurar un respaldo en un
entorno limpio y comprobar que los datos están.** Es una hora de trabajo que
evita un incidente de días.

## 6. Cifrado

Los respaldos contienen datos personales: RUT de conductores, teléfonos, datos
de documentos de identidad.

| Opción | Comentario |
|---|---|
| Cifrado en reposo del servidor | Lo más simple, cubre todos los respaldos |
| Destino cifrado | Es lo que se recomienda con almacenamiento externo |
| `pg_dump` a disco local sin cifrar | Aceptable solo si el disco está cifrado |

> **Pendiente:** decidir y documentar el destino de los respaldos con el
> cliente, incluyendo dónde quedan y quién tiene acceso.

## 7. Copia de los archivos del cliente

Los documentos escaneados no están en este sistema. Si el cliente los guarda
por su cuenta, su respaldo es responsabilidad suya.

Cuando se implemente el almacenamiento de archivos (pendiente), habrá que
respaldar también el volumen, con las mismas precauciones.

## 8. Verificación del respaldo

Un respaldo que no se verifica se deja de hacer en silencio, siempre pasa lo
mismo: nadie se da cuenta hasta que se necesita.

**Cada día, automáticamente:**

1. Generar el respaldo.
2. **Comprobar que el archivo existe y no está vacío.**
3. **Restaurarlo en una base limpia y contar las tablas.**

El paso 3 es el que importa. Un respaldo que se genera bien y que no se puede
restaurar no sirve para nada.

```bash
# Verificación mínima diaria.
pg_restore --list ./respaldos/flota-$(date +%F).dump > /dev/null && echo "OK"
```

`pg_restore --list` lee la estructura del archivo. Si está corrupto, falla ahí, y
es mucho mejor que descubrirlo durante una emergencia.

## 9. Resumen

| Pregunta | Respuesta |
|---|---|
| ¿Qué se respalda? | La base de datos completa. |
| ¿Cada cuánto? | Todos los días. |
| ¿Dónde queda? | **Pendiente de decidir con el cliente.** |
| ¿Está cifrado? | **Pendiente de decidir.** |
| ¿Se probó una restauración? | **No.** |
| ¿Hay verificación automática? | Escrita, no implementada. |
| ¿Los archivos del cliente están incluidos? | No, y no deben estar: son suyos. |