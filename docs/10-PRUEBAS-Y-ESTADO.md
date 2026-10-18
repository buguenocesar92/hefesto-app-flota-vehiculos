# 10 - Pruebas y estado

## 1. Estado real

**Código escrito, sin ejecutar.**

En el entorno donde se construyó esta aplicación **no había Docker, ni PHP, ni
Composer, ni PostgreSQL**. Concretamente:

| Herramienta | Disponible | Consecuencia |
|---|---|---|
| Docker | No | Ninguna imagen se construyó. Ningún compose se levantó. |
| PHP | No | No se ejecutó ni una línea del backend. |
| Composer | No | No se instaló nada. No existe `vendor/`. |
| PostgreSQL | No | Ninguna migración corrió. La RLS no se probó. |
| Node | No | El frontend no se compiló. |
| PHPUnit | No | **Ninguna prueba se ejecutó.** |
| Red | No | No se verificó documentación externa en el momento. |

### Lo que eso significa, sin adornos

- Las pruebas están **escritas**, y su contenido refleja el comportamiento
  esperado. **No hay un solo resultado de ejecución que mostrar.**
- Las migraciones están escritas y son consistentes entre sí (el orden de las
  claves foráneas se revisó a mano), pero **nunca se aplicaron**.
- Los archivos de Docker y Compose están escritos y usan versiones fijadas, pero
  **nunca se construyeron**. Que las etiquetas de imagen existan en el registro
  **no está verificado**.
- El catálogo de documentos y sus plazos **no se verificaron contra normativa**.

Nada de lo anterior se presenta como probado. Un sistema de alertas que se
entrega diciendo "funciona" sin haber corrido una sola vez es exactamente el
problema que este producto vino a resolver.

## 2. Qué hay escrito

| Área | Cantidad | Estado |
|---|---|---|
| Migraciones | 15 | Escritas, nunca aplicadas |
| Modelos Eloquent | 16 | Escritos, nunca cargados |
| Enums del dominio | 4 | Escritos |
| Servicios | 6 | Escritos |
| Controladores | 11 | Escritos |
| Policies | 4 | Escritas |
| FormRequests | 2 | Escritos |
| Comandos de consola | 2 | Escritos |
| Trabajos de cola | 1 | Escrito |
| Pruebas | 9 archivos | Escritas, **nunca ejecutadas** |
| Semilla | 3 seeders | Escrita, nunca ejecutada |
| Páginas Nuxt | 3 | Escritas, nunca compiladas |

## 3. Las pruebas prioritarias

### 3.1 Aislamiento entre flotas

`api/tests/Feature/Aislamiento/AislamientoEntreFlotasTest.php`

Suite propia (`php artisan test --testsuite=Aislamiento`).

| Prueba | Qué comprueba |
|---|---|
| Un vehículo de otra flota no aparece en el listado | Aislamiento en lectura |
| No se puede leer por identificador directo | Aislamiento en la consulta |
| Los documentos no cruzan | Aislamiento en el módulo central |
| **Una consulta sin contexto falla cerrado** | La función de contexto lanza excepción |
| La función devuelve el tenant fijado | Funciona como se espera |
| No se puede escribir en nombre de otra flota | `WITH CHECK` |

**Las tres últimas requieren PostgreSQL** y se saltan solas en SQLite, porque
SQLite no tiene Row Level Security. El salto queda registrado en la salida de
PHPUnit: no se declaran probadas si no se ejecutaron contra la base real.

### 3.2 La alerta única por documento y período

`api/tests/Feature/Alertas/AlertaUnicaPorDocumentoYPeriodoTest.php`

Suite propia (`php artisan test --testsuite=Alertas`). Es la prueba más
importante del sistema.

| Prueba | Qué comprueba |
|---|---|
| Genera la alerta dentro de la ventana | El caso normal |
| **Reejecutar no duplica** | Tres ejecuciones más, cero filas nuevas |
| Umbrales distintos son periodos distintos | 30 y 15 no se pisan |
| Un documento vencido genera la de vencido | El tipo correcto |
| Un documento sin fecha no genera alertas | No inventa plazos |
| **Renovar abre un período nuevo** | La fecha nueva no queda bloqueada |
| **El índice único impide el duplicado** | Inserción doble a mano, la base rechaza |
| Dos flotas no se pisan | Aislamiento del generador |

### 3.3 El estado en dos ejes

`api/tests/Feature/EstadoVehiculoEnDosEjesTest.php`

Comprueba el requisito del dueño de la flota: un vehículo en mantención con
documentos vencidos tiene que **ver los dos estados**.

Si alguien refactoriza el cálculo y vuelve a un único campo, esta prueba falla.
Ese es exactamente el objetivo.

### 3.4 El kilometraje con su fuente

`api/tests/Feature/RegistroDeKilometrajeTest.php`

| Prueba | Qué comprueba |
|---|---|
| La lectura guarda su fuente y se encadena | El modelo de datos |
| Un kilometraje menor se rechaza | El error de tipeo no pasa |
| Un ajuste con motivo y autor sí se acepta | La corrección es posible, pero queda auditada |
| El kilometraje del taller es fuente de primer nivel | El taller cuenta igual |

### 3.5 Lógica pura

`api/tests/Unit/CalculoDeDiasRestantesTest.php` y
`api/tests/Unit/NormalizadorPatenteTest.php`

Sin base de datos. El cálculo de días que faltan merece una prueba propia: un
documento que vence hoy tiene **cero** días, no menos uno, y esa diferencia
separa el mensaje "vence hoy" de "venció ayer".

La normalización de patente merece la otra: si `"AB CD 12"`, `"AB·CD-12"` y
`"ABCD12"` se guardaran distinto, la flota se duplica y los avisos se mandan
triple.

## 4. Cómo ejecutarlas cuando haya PHP

```bash
cd api
composer install

# Todo.
php artisan test

# Por suite.
php artisan test --testsuite=Aislamiento
php artisan test --testsuite=Alertas
php artisan test --testsuite=Unidad

# Contra PostgreSQL de verdad (obligatorio para lo de RLS).
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 php artisan test --testsuite=Aislamiento
```

El motor por defecto de `phpunit.xml` es SQLite en memoria, para que la suite
corra en cualquier máquina. **La RLS no existe en SQLite**: las pruebas que la
necesitan se saltan solas en vez de fingir que pasaron.

## 5. Lo que falta probar, en orden

| # | Qué | Por qué importa |
|---|---|---|
| 1 | **Ejecutar la suite en SQLite** | Detecta errores de sintaxis, imports rotos y firmas malas. Es lo más barato y lo que más encuentra. |
| 2 | **Aplicar las migraciones en PostgreSQL** | Verifica las restricciones, los índices parciales y el orden de las claves foráneas. |
| 3 | **Ejecutar la suite de aislamiento en PostgreSQL** | Es la única forma de probar la RLS de verdad. |
| 4 | **Levantar el compose completo** | Verifica las imágenes, las variables y la conexión entre servicios. |
| 5 | **Reejecutar `flota:generar-alertas` dos veces** | Comprobar en la base real que la segunda da cero nuevas. |
| 6 | **Construir el frontend** | Verificar que el SSR y el proxy funcionan. |
| 7 | **Prueba de entrega de correo** | Solo al final, con datos reales. |

Los puntos 1 a 3 son los que separan "código escrito" de "código que funciona".

## 6. Pendientes que no son técnicos

### 6.1 Los plazos legales — EL PENDIENTE PRINCIPAL

**No se verificó ninguna normativa chilena en este entorno.**

El catálogo de tipos de documento se carga **sin plazos legales**, y las
columnas que los guardarían quedan vacías a propósito. El sistema funciona igual
porque usa **las fechas que vienen impresas en cada documento**, que no
necesitan ser deducidas.

Lo que falta:

| Qué | Quién lo resuelve |
|---|---|
| Vigencia de cada tipo de documento | Un profesional del transporte, o el cliente |
| Qué documentos exige cada tipo de servicio | El cliente, que conoce su operación |
| Periodicidad de las inspecciones | Un profesional del transporte |
| Consecuencia de circular sin cada documento | Un profesional legal |

> **Escribir un plazo legal inventado en un sistema de gestión es un riesgo
> serio.** Un operador que confía en un número inventado deja de mirar el
> documento físico, y el sistema le dio una confianza que no tenía respaldo.

Una vez confirmados, se cargan con su fuente:

```sql
UPDATE tipos_documento
SET plazo_legal_dias = <valor>,
    plazo_legal_fuente = '<norma o artículo>',
    plazo_legal_confirmado_por = '<persona>',
    plazo_legal_confirmado_at = now()
WHERE clave = '<tipo>';
```

Y recién a partir de ahí el sistema puede considerarlos confirmados.

### 6.2 Flujo de login

El middleware de autenticación está escrito y acepta un token por cabecera o
cookie. **La página de login con Authentik no está implementada**: falta el
intercambio del código de autorización y el almacenamiento del token.

### 6.3 Almacenamiento de documentos escaneados

No se implementó subir el PDF o la foto del permiso de circulación. El sistema
guarda los **datos**, no los archivos.

Es una decisión consciente para esta versión: el manejo de archivos es
superficie de ataque, y hacerlo sin haber probado nada sería peor que no
hacerlo.

### 6.4 Proxy inverso y TLS

El compose expone puertos. Falta el proxy con TLS y las cabeceras de seguridad
que corresponden.

### 6.5 Versiones de imagen

Las etiquetas fijadas (`postgres:16.4-alpine`, `redis:7.2.5-alpine`,
`php:8.3-fpm-bookworm`, `node:20.11-bookworm-slim`, `authentik:2024.6`) **no se
verificaron contra el registro** en este entorno, porque no había red.

Si alguna no existiera, el compose fallaría al construir, que es visible. Pero
conviene confirmarlas antes del primer despliegue.

### 6.6 Registro de auditoría

No hay registro de quién cambió qué. Para un sistema donde se gestionan
vencimientos, debería haberlo.

## 7. Resumen para el cliente

| Pregunta | Respuesta |
|---|---|
| ¿Funciona? | Escrito y consistente, **sin ejecutar**. |
| ¿Las pruebas pasan? | **No se ejecutaron.** No hay resultados. |
| ¿El aislamiento entre flotas funciona? | Diseñado en dos capas; **sin verificar contra PostgreSQL**. |
| ¿Las alertas se duplican? | La base lo impide por índice único; **sin verificar en ejecución**. |
| ¿Los plazos legales son correctos? | **No hay plazos legales cargados**, a propósito. Hay que confirmarlos. |
| ¿Se puede usar en producción? | **No.** Faltan las pruebas de la sección 5. |
| ¿Reemplaza el control documental? | No. Ver el [README](../README.md). |