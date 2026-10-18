# 07 - Seguridad

Los riesgos están escritos con frases claras. Un documento de seguridad que solo
dice "implementamos las mejores prácticas" no sirve para decidir nada.

## 1. Qué protege este sistema

- Documentos de vehículos y conductores de flotas que no son de Hefesto.
- Patentes, VIN, RUT de conductores y datos de Scout.
- El historial de la flota, que sirve para responder ante un accidente o una
  fiscalización.

## 2. Amenazas y mitigaciones

### 2.1 Fuga de datos entre flotas

**Es el riesgo número uno.** No es que falle una pantalla: es que el dueño de una
flota vea los documentos de otra.

| # | Mitigación | Qué detecta |
|---|---|---|
| 1 | RLS con `tenant_id` en todas las tablas | Todo lo que se le escape a la aplicación |
| 2 | `WITH CHECK` en las políticas | Escrituras en nombre de otra flota |
| 3 | `FORCE ROW LEVEL SECURITY` | Que el dueño de la tabla no salte la política |
| 4 | Contexto **transaccional** | Que el worker de cola arrastre el tenant de otra petición |
| 5 | Función de contexto que **falla cerrado** | Operaciones ejecutadas sin tenant |
| 6 | `flota:verificar-aislamiento` | Rol con privilegios de más, tablas sin política |
| 7 | Pruebas de aislamiento | Intentar la fuga y fallar |

La base es la última línea: si la aplicación tiene un error, PostgreSQL sigue
sin entregar datos de otra flota.

**Lo que NO se hizo:** las pruebas de aislamiento están escritas pero **no se
ejecutaron**. Ver [`10-PRUEBAS-Y-ESTADO.md`](10-PRUEBAS-Y-ESTADO.md).

### 2.2 El rol de la aplicación con superusuario

Si `DB_USERNAME` es superusuario o tiene `BYPASSRLS`, PostgreSQL **ignora todas
las políticas**. El sistema sigue funcionando, la interfaz se ve perfecta y una
flota ve los datos de otra sin ningún error visible.

Es el fallo más peligroso del sistema, porque es **silencioso**.

`php artisan flota:verificar-aislamiento` lo comprueba y falla. **Ejecutarlo en
el primer despliegue real y después de cada cambio de permisos.**

### 2.3 Tokens de sesión

| Medida | Detalle |
|---|---|
| Se guarda el **hash** | SHA-256 del token, nunca el token |
| Solo en el servidor | Nuxt lo reenvía; nunca llega al código del navegador |
| Cookie **httpOnly** | No la puede leer JavaScript |
| Desactivación inmediata | Un usuario con `activo = false` pierde el acceso al instante |
| Mensajes genéricos | El mismo 401 para token inválido, usuario inexistente o desactivado |

El mismo mensaje en los tres casos evita enumerar cuentas probando tokens.

**Lo que falta:** rotación de tokens y revocación desde la interfaz. Hoy la
revocación es desactivar el usuario.

### 2.4 CSRF

La API es sin cookies de sesión y sin formularios: usa `Authorization: Bearer`.
Por lo tanto **no hay superficie CSRF**, y la exclusión en `bootstrap/app.php`
está explícita, no puesta por omisión.

Si alguna vez se agrega autenticación por cookie a la API, hay que **quitar**
esa exclusión y revisar el resto.

### 2.5 Validación y autorización

| Capa | Qué hace |
|---|---|
| **FormRequest** | Valida y normaliza la entrada (por ejemplo, la patente) |
| **Policy** | Decide si ese usuario puede hacer eso en SU flota |
| **Base de datos** | Restricciones que no dependen del código |

La base es la última: hay reglas que no se pueden saltar aunque alguien escriba
directamente por SQL. Están listadas en
[`02-MODELO-DE-DATOS.md`](02-MODELO-DE-DATOS.md) sección 7.

### 2.6 Se filtran datos en las respuestas

- El RUT del tenant está en `$hidden` del modelo.
- Los errores devuelven un mensaje genérico y un código, **nunca una traza**.
- Las excepciones internas se registran en el log con `report()` y no llegan al
  cliente.
- Los errores de base que rompen la RLS se registran con voz propia, porque son
  un incidente de aislamiento y no un error de usuario.

### 2.7 Enumeración de flotas

Un `slug` se puede enumerar antes de autenticarse, porque es público: aparece en
la URL de entrada y en los enlaces que la flota reparte.

Un atacante puede descubrir que "Flota Norte" usa el sistema. **No puede ver más
que el nombre.**

Si eso se considera inaceptable, la alternativa es dejar el campo en blanco
hasta el login y resolver siempre por dominio de correo. Queda anotado como
decisión pendiente del cliente, no como algo resuelto.

## 3. Controles que faltan

Esto es honesto y hay que decirlo claro:

| Control | Estado |
|---|---|
| Revisión de seguridad externa | **No hay** |
| Pruebas de penetración | **No hay** |
| Auditoría de dependencias | **No hay** |
| Escaneo de secretos en el repositorio | **No configurado** |
| Registro de auditoría de acciones | **No implementado** |
| Cifrado en reposo de la base | **Depende de la infraestructura** |
| Prueba automática en integración continua | **No configurada** |

Un sistema sin revisión externa y sin registro de auditoría no puede presentarse
a un cliente como listo para producción. Puede presentarse como **en
construcción**, que es lo que es.

## 4. Sobre los datos personales

El sistema guarda RUT de conductores, teléfonos, correos y fotografías de
documentos de identidad (futuro).

Para una pyme chilena, eso implica:
- Ley 19.628 sobre protección de la vida privada.
- Deberes de seguridad y confidencialidad en el tratamiento de esos datos.

La aplicación ayuda con el cumplimiento, pero no lo garantiza: depende de que el
cliente tenga una política de acceso y una cláusula de tratamiento de datos con
sus conductores.

> **Pendiente:** revisar con un profesional legal el tratamiento de datos
> personales en el contrato con el cliente.

## 5. Verificación antes de publicar

```bash
# 1. ¿El aislamiento está realmente activo?
php artisan flota:verificar-aislamiento

# 2. ¿La aplicación está en modo producción?
echo $APP_DEBUG      # debe ser false

# 3. ¿Los puertos de datos están cerrados al exterior?
#    PostgreSQL y Redis solo en 127.0.0.1.

# 4. ¿Hay secretos en el repositorio?
grep -r "password\|secret\|token" --include="*.env*" .
#    Solo .env.example, y sin valores reales.

# 5. ¿La tarea programada corre una sola vez?
docker compose -f deploy/compose.desarrollo.yaml logs scheduler
```

## 6. Resumen honesto

**Lo que está bien:** el aislamiento entre flotas descansa en la base de datos y
falla cerrado; los tokens se guardan hasheados; no hay superficie CSRF; las
reglas de negocio críticas están en la base y no solo en el código; y no se
inventan plazos legales.

**Lo que falta:** ejecución de las pruebas, revisión externa, registro de
auditoría, flujo de login completo, y la confirmación de los plazos legales por
un profesional del transporte o con el cliente.

Esa lista es el trabajo que falta, no un detalle de forma.