# 04 - Instalación

> **Nada de esto se ejecutó.** No había Docker, ni PHP, ni Composer en el
> entorno donde se escribió el sistema. Los comandos son los que se
> seguirían, y algunos detalles quedan pendientes de ajustar en la máquina
> real. Ver [`10-PRUEBAS-Y-ESTADO.md`](10-PRUEBAS-Y-ESTADO.md).

## 1. Requisitos

- Docker y Docker Compose v2.
- Un dominio o túnel para Authentik.
- ~2 GB de RAM libres para el stack completo.

## 2. Preparar el `.env`

```bash
cd apps/flota-vehiculos
cp .env.example .env
```

Completar **como mínimo**:

| Variable | Por qué |
|---|---|
| `APP_KEY` | Sin ella la aplicación no arranca |
| `DB_PASSWORD` | Rol de aplicación |
| `DB_MIGRATION_PASSWORD` | Rol de migraciones |
| `REDIS_PASSWORD` | Redis sostiene el candado de la tarea programada |

Generar la clave:

```bash
docker compose -f deploy/compose.desarrollo.yaml run --rm api php artisan key:generate
```

### Sobre el rol de PostgreSQL

`DB_USERNAME` es el rol que usa la aplicación. **No puede ser superusuario ni
tener `BYPASSRLS`.**

Con cualquiera de las dos cosas, PostgreSQL **ignora todas las políticas de
Row Level Security**: el sistema seguiría funcionando, la interfaz se vería
perfecta, y una flota vería los datos de otra sin ningún error visible. Es la
forma más peligrosa de fallo posible en este sistema, porque es silenciosa.

El comando `flota:verificar-aislamiento` lo comprueba. Ver
[`07-SEGURIDAD.md`](07-SEGURIDAD.md).

## 3. Authentik

Authentik **queda fuera del compose** a propósito. Es un servicio de identidad
con su propia base de datos y su propio ciclo de vida, y suele ser compartido
entre varias aplicaciones de Hefesto. Instalar uno y apuntar esta aplicación a él
es más sensato que levantar una identidad distinta por sistema.

Si la instalación es nueva y no hay Authentik en el servidor, ver
[`kits/hefesto-seguridad-crowdsec`](../../kits/) y la documentación de Authentik
oficial para el despliegue. **Pendiente:** documentar aquí los pasos exactos
para una instalación nueva, verificados contra una instancia real.

En Authentik hay que crear:

1. Un **proveedor OpenID Connect** con el cliente configurado.
2. Una **aplicación** de tipo OpenID Connect, con el redirect URI:

   ```
   https://<dominio-web>/api/auth/callback
   ```

3. Un **grupo** para los usuarios de esta aplicación, por ejemplo
   `flota-vehiculos`, con un atributo que identifique la flota.

El atributo de grupo es lo que permite que un usuario pertenezca a una flota
concreta. Ver [`05-CONFIGURACION.md`](05-CONFIGURACION.md).

## 4. Levantar el stack

```bash
docker compose -f deploy/compose.desarrollo.yaml --env-file .env up -d
```

Servicios que quedan arriba:

| Servicio | Puerto | Qué es |
|---|---|---|
| `web` | 3000 | Interfaz Nuxt |
| `api` | 8000 | API Laravel |
| `postgres` | 5432 | Base de datos (solo en loopback) |
| `redis` | 6379 | Caché y colas (solo en loopback) |
| `scheduler` | — | `php artisan schedule:work` |
| `worker` | — | `php artisan queue:work` |

`postgres` y `redis` solo escuchan en `127.0.0.1`. No están expuestos a la red.

## 5. Migraciones y semilla

```bash
# Aplicar el esquema.
docker compose -f deploy/compose.desarrollo.yaml exec api php artisan migrate --force

# Cargar el catálogo de tipos de documento y una flota de ejemplo.
docker compose -f deploy/compose.desarrollo.yaml exec api php artisan db:seed --force
```

La semilla carga **una flota de ejemplo** con seis vehículos, documentos con
fechas repartidas en todas las ventanas (vencidos, hoy, esta semana, en un mes,
en seis meses), lecturas de kilometraje de tres fuentes distintas y una
mantención en curso sobre un vehículo con el seguro vencido.

Esos datos son para probar el sistema, no para mostrar a un cliente.

## 6. Verificar antes de declarar nada

```bash
# ¿El aislamiento entre flotas está realmente activo?
docker compose -f deploy/compose.desarrollo.yaml exec api php artisan flota:verificar-aislamiento

# ¿Genera las alertas? ¿Duplica algo al reejecutar?
docker compose -f deploy/compose.desarrollo.yaml exec api php artisan flota:generar-alertas
docker compose -f deploy/compose.desarrollo.yaml exec api php artisan flota:generar-alertas
```

El segundo comando **tiene que dar cero alertas nuevas**. Si no, hay un
problema y no hay que seguir hasta entenderlo.

```bash
# ¿La API responde?
curl -s http://localhost:8000/api/salud
```

## 7. El planificador

```bash
docker compose -f deploy/compose.desarrollo.yaml logs -f scheduler
```

Y una ejecución manual, para probar sin esperar a la hora:

```bash
docker compose -f deploy/compose.desarrollo.yaml exec scheduler \
    php artisan flota:generar-alertas --tenant=flota-demo
```

El tablero muestra la fecha de la última generación. Si dice "nunca se ha
generado alertas", la tarea no está corriendo, y eso hay que resolverlo antes
de confiar en el tablero vacío.

## 8. Primer acceso

La tabla `usuarios` está vacía después de migrar. El primer usuario hay que
crearlo a mano, con el `authentik_sub` que entrega Authentik:

```bash
docker compose -f deploy/compose.desarrollo.yaml exec api php artisan tinker
```

```php
App\Models\Usuario::create([
    'tenant_id' => (string) App\Models\Tenant::where('slug', 'flota-demo')->value('id'),
    'authentik_sub' => 'el-sub-que-entrega-authentik',
    'token_hash' => hash('sha256', 'el-token-de-sesion'),
    'nombre' => 'Administrador inicial',
    'correo' => 'admin@ejemplo.cl',
    'rol' => 'administrador',
    'activo' => true,
]);
```

> **Pendiente:** el flujo de login completo con Authentik (intercambio de
> código, almacenamiento del token) está **diseñado pero no implementado** en
> el código entregado. El middleware acepta un token que llega en la cabecera
> `Authorization` o en la cookie `flota_token`, pero la página de login que lo
> obtiene no existe. Hay que construirla antes del primer uso real.

## 9. Detalle de despliegue que se olvida

`opcache.validate_timestamps = 0` está activado en la imagen. Eso significa que
**cada despliegue exige reiniciar PHP-FPM**:

```bash
docker compose -f deploy/compose.desarrollo.yaml restart api worker scheduler
```

Sin ese reinicio, el código nuevo no se carga nunca y se sigue ejecutando el
viejo. En un sistema de alertas, seguir ejecutando la versión anterior sin
enterarse es peor que estar caído.

## 10. Requisitos previos que hay que ajustar en la máquina real

Estas cosas no se pudieron verificar sin un entorno de ejecución:

| Tema | Qué falta |
|---|---|
| Versiones de imagen | Verificar que las etiquetas fijadas existan en el registro |
| `composer.lock` | No está en el repositorio: se genera en el primer `composer install` |
| Extensiones PHP | La imagen las instala; falta verificar que `laravel/framework` ^12 no pida alguna más |
| Connection string de Authentik | Pendiente de una instancia real |
| Nginx o proxy inverso | El compose expone puertos; falta el proxy con TLS |

Ver [`10-PRUEBAS-Y-ESTADO.md`](10-PRUEBAS-Y-ESTADO.md) para el detalle.