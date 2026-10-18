# 03 - Multitenancy y roles

Este es el documento más importante del sistema. El riesgo número uno no es que
una pantalla falle: es que el dueño de una flota vea los documentos de otra.

## 1. El patrón: columna `tenant_id` más Row Level Security

Mismo patrón que usa el resto de las aplicaciones de Hefesto Studio
(`apps/portal-autoservicio`). Una sola convención en la casa.

Todas las tablas de negocio llevan `tenant_id`, y PostgreSQL aplica una
política de **Row Level Security** que exige que `tenant_id` sea el de la
petición.

### Por qué no un esquema por flota

La alternativa clásica es darle a cada flota su propio esquema
(`flota_a.documentos`, `flota_b.documentos`). Se evaluó y se descartó:

1. **Las migraciones se multiplican.** Con esquema por cliente, cada migración
   corre en todos los esquemas. Con 30 flotas, 30 ejecuciones. Con 300, 300. Y
   una migración que se aplica bien en un esquema y mal en otro deja clientes
   con la columna nueva y clientes sin ella, justo cuando una consulta nueva
   recorre las dos.

2. **El pool de conexiones se vuelve el cuello de botella.** El pool se
   dimensiona por combinación de (esquema, rol). Con muchas flotas, un pool
   razonable deja de alcanzar en horas punta.

3. **Laravel obliga a duplicar la lógica de esquema.** La conexión depende del
   usuario conectado, y cada consulta, cada migración y cada cola necesita
   acordarse del esquema correcto. Es la clase de acoplamiento que produce
   fugas: un `DB::table()` olvidado.

4. **Resumir una flota se complica.** `pg_dump -n cliente_1` suena bien, pero en
   la práctica obliga a scripts por cliente, y el operador termina respaldando
   la base entera igual.

5. **El aislamiento queda en manos de la aplicación.** El `search_path` es un
   parámetro de sesión. Si queda mal puesto en una conexión reutilizada, la
   consulta va al esquema equivocado **y no falla**: devuelve los datos de la
   flota equivocada sin error visible. Es exactamente el fallo que RLS evita,
   porque en RLS el tenant viaja en la propia fila y la política compara fila
   por fila.

### El costo del patrón elegido

Todas las consultas necesitan contexto de tenant. Ese costo está resuelto y
forzado por el diseño: la función de contexto **falla cerrado**.

## 2. El mecanismo

### 2.1 La función de contexto

```sql
CREATE FUNCTION public.app_tenant_id() RETURNS uuid ...
    valor := current_setting('app.tenant_id', true);
    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION ...  -- sin tenant: no hay datos, y se dice por qué
    RETURN valor::uuid;
```

**Lanza excepción** si no hay contexto. Podría devolver `NULL` y filtrar todo
en silencio, pero se eligió que falle con ruido:

- Si devuelve `NULL`, la consulta devuelve cero filas sin aviso. Alguien lo
  "arregla" devolviendo `NULL` cuando hace falta (un reporte, un comando) y la
  puerta queda abierta sin que nada lo delate.
- Si lanza excepción, el error aparece en el log **en el momento**, y se sabe
  exactamente qué operación se escapó del middleware.

Es el mismo criterio que un candado: importa que falle cerrado.

### 2.2 La política

```sql
ALTER TABLE public.documentos ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.documentos FORCE ROW LEVEL SECURITY;

CREATE POLICY politica_aislamiento_documentos ON public.documentos
    FOR ALL TO PUBLIC
    USING      (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

Tres detalles que importan:

- **`USING`** filtra lo que se **lee**.
- **`WITH CHECK`** impide **escribir** una fila con otro `tenant_id`. Sin esto,
  un `INSERT` con el tenant equivocado pasaría la lectura y dejaría una fila
  invisible que nadie podría ver ni borrar.
- **`FORCE ROW LEVEL SECURITY`** hace que la política aplique **también al dueño
  de la tabla**. Sin `FORCE`, el dueño la saltaría. Y en desarrollo el rol de la
  aplicación suele ser el mismo que creó las tablas: sin `FORCE`, el aislamiento
  sería decorativo justo donde se prueba.

### 2.3 Cómo viaja el tenant

```php
DB::select('SELECT set_config(?, ?, true)', ['app.tenant_id', $tenantId]);
```

El `true` final es lo más importante del archivo: **alcance de transacción**. Al
confirmar o revertir, PostgreSQL descarta el valor solo.

Con un `SET` normal (ámbito de sesión) el valor sobreviviría a la petición. Y el
**worker de colas reutiliza la misma conexión** entre trabajos de flotas
distintas: un `SET` a sesión significaría que un trabajo de la flota A puede leer
los datos de la flota B. Por eso `ContextoTenant::establecer()` **lanza
excepción si no está dentro de una transacción**.

### 2.4 El orden de los middleware

Hay una dependencia en círculo que hay que romper con cuidado:

- `ResolverTenant` corre globalmente y fija el tenant de la petición, pero
  necesita un usuario.
- `AutenticarConToken` resuelve el usuario, pero para leer `usuarios` necesita un
  tenant, y `usuarios` tiene RLS.

La secuencia real es:

1. La petición trae un token y el `X-Flota-Slug`.
2. La flota se resuelve por `vw_tenants_publicos`, la única lectura posible sin
   contexto de tenant.
3. Se abre la transacción con ese tenant y se busca al usuario **dentro de él**.
4. `ResolverTenant` fija el contexto para el resto de la petición.

**El slug no es una credencial.** Es público: aparece en la URL de entrada y en
los enlaces que la flota reparte. Con el slug se resuelve el contexto, pero la
autenticación sigue exigiendo el token, y se verifica que el usuario
encontrado pertenezca a ese mismo tenant. Pedir datos de otra flota es imposible:
el token no existe fuera de su flota.

## 3. Roles

Un usuario no tiene rol global: es administrador **de su flota** y nada fuera de
ella. La RLS ya garantiza que no alcance datos ajenos; los roles ordenan lo que
puede hacer **dentro** de los suyos.

| | Consulta | Operador | Administrador |
|---|---|---|---|
| Ver vehículos y documentos | sí | sí | sí |
| Ver el tablero de vencimientos | sí | sí | sí |
| Cargar vehículos y documentos | no | sí | sí |
| Registrar kilometraje | no | sí | sí |
| Cargar mantenciones y combustible | no | sí | sí |
| Renovar un documento | no | sí | sí |
| Anular un documento | no | no | sí |
| Retirar un vehículo | no | no | sí |
| Cambiar la configuración de alertas | no | no | sí |
| Marcar una alerta como resuelta | no | no | sí |
| Reintentar el envío de una alerta | no | sí | sí |

### Por qué renovar un documento NO es tarea de lectura

Si un rol de solo lectura pudiera renovar documentos, podría **silenciar una
alerta** cargando una fecha nueva. La operación que "cierra" el problema es
precisamente la que no puede quedar abierta al que solo mira.

### Por qué una lectura de kilometraje es inmutable

Una lectura es un **registro de un hecho**, no un dato editable. Si el odómetro
se lee mal, se corrige con un ajuste posterior que queda anotado, no borrando el
número anterior: la diferencia entre las dos versiones es lo que permite
entender qué pasó con ese vehículo.

Un kilometraje que se puede borrar es un kilometraje en el que nadie puede
confiar, y entonces todo el plan de mantenciones por distancia deja de servir.
La Policy devuelve `false` en `update` y en `delete`, sin excepciones.

## 4. El punto ciego: `tenants` sin RLS

`tenants` **no lleva RLS**, a propósito: se consulta en el login, cuando no hay
sesión. Es una excepción consciente con dos compensaciones:

1. **La vista `vw_tenants_publicos`**: tres columnas, solo flotas activas. No
   expone razón social, RUT, correos ni teléfonos.

2. **El rol de la aplicación no es superusuario.** Quien tenga acceso SQL
   directo puede leer `tenants` entera.

**Riesgo residual, dicho con todas las letras:** un `slug` se puede enumerar
antes de autenticarse, porque es público. Un atacante puede descubrir que la
flota "Flota Norte" usa el sistema. No puede ver más que su nombre.

Si incluso eso se considera inaceptable, la alternativa es dejar el campo en
blanco hasta el login y resolver siempre por dominio de correo. Queda anotado
como decisión pendiente del cliente, no como algo resuelto.

## 5. La verificación

```bash
php artisan flota:verificar-aislamiento
```

Falla si:

- el rol de la aplicación es superusuario o tiene `BYPASSRLS`,
- alguna tabla con `tenant_id` no tiene RLS activada,
- alguna tabla tiene RLS sin `FORCE`,
- la función `app_tenant_id()` dejó de lanzar excepción cuando falta el contexto,
- no existe la función de contexto.

Se ejecuta al desplegar. Ver [`07-SEGURIDAD.md`](07-SEGURIDAD.md).

## 6. Riesgos que siguen abiertos

| Riesgo | Mitigación actual | Qué falta |
|---|---|---|
| Fuga entre flotas | RLS con `FORCE`, `WITH CHECK`, contexto transaccional | Las pruebas existen y **no se ejecutaron** |
| Rol de aplicación con privilegios de más | `flota:verificar-aislamiento` | Ejecutarlo en el primer despliegue real |
| Enumeración de `slug` | Solo expone nombre | Decidir con el cliente si importa |
| Tabla nueva sin política | La migración falla si falta el `tenant_id` | Revisar la lista en cada migración nueva |
| Token de sesión filtrado | Se guarda el hash SHA-256, cookie httpOnly | Rotación y revocación |

**Lo que NO se hizo:** no hay revisión de seguridad externa, no hay pruebas de
penetración, y no hay auditoría de código por terceros. Ver
[`07-SEGURIDAD.md`](07-SEGURIDAD.md).