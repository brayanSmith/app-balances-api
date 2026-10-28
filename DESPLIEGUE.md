# Despliegue

- **API (este repo):** Laravel Cloud.
- **App móvil (`app-balances-mobile`):** EAS Build + Google Play.

## 1. API en Laravel Cloud

### Crear la aplicación

1. En [cloud.laravel.com](https://cloud.laravel.com), **New application** → conecta GitHub y elige `brayanSmith/app-balances-api`, rama `main`.
2. Elige la región más cercana a tus usuarios (para Colombia, una de Estados Unidos este).

### Recursos del entorno

| Recurso | Qué hacer |
|---|---|
| **Base de datos** | Crea una **MySQL** y conéctala al entorno. Cloud agrega solo las variables `DB_*`. |
| **Almacenamiento** | Crea un **bucket privado** y conéctalo. Nombre del disco: `archivos`, y marca **"Use as default disk"**. Ahí se guardan comprobantes, fotos de perfil y los ZIP de importación. |
| **Scheduler** | En el cómputo de la app, activa el **Scheduler**: borra cada noche los ZIP de importación que no se usaron (`importaciones:limpiar`). |

> Si el bucket no queda como disco por defecto, los archivos se guardan en el disco del servidor y **se pierden en cada despliegue**.

### Comandos

- **Build:** `composer install --no-dev --optimize-autoloader`. Las líneas de `npm` se pueden quitar: la API no tiene frontend.
- **Deploy:** `php artisan migrate --force`

### Variables de entorno

`APP_KEY` y `APP_URL` las pone Cloud. Agrega:

```dotenv
APP_NAME=Balances
APP_ENV=production
APP_DEBUG=false
APP_LOCALE=es
APP_FALLBACK_LOCALE=es

LOG_LEVEL=warning

# Correo real (recuperar contraseña). Ejemplo con Resend; con Brevo o Gmail cambian host, usuario y clave.
MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_USERNAME=resend
MAIL_PASSWORD=tu_api_key
MAIL_FROM_ADDRESS=no-responder@tudominio.com
MAIL_FROM_NAME=Balances
```

### Comprobar

1. **Deploy** y espera a que termine (incluye las migraciones).
2. Abre `https://TU-APP.laravel.cloud/api/ping`: debe responder `{"ok":true}`.
3. Opcional: conecta tu dominio (por ejemplo `api.tudominio.com`) en **Domains**. Cloud gestiona el HTTPS.

### Notas

- Los **RAR** no se pueden importar en Cloud (no tiene 7-Zip); los **ZIP** sí. La app ya muestra un mensaje que lo explica.
- Detrás del balanceador de Cloud se confía en los proxies (`bootstrap/app.php`), para que los enlaces firmados de comprobantes y fotos usen `https`.

## 2. App Android con EAS

1. En `mobile/eas.json`, reemplaza `https://CAMBIAR-POR-TU-DOMINIO/api` (en los dos perfiles) por la URL real de la API, terminada en `/api`.
2. Instala y enlaza EAS (una sola vez):
   ```powershell
   npm install -g eas-cli
   cd C:\laragon\www\balances\mobile
   eas login
   eas init        # crea el proyecto en expo.dev y agrega su id a app.json
   ```
3. **APK de prueba** (se instala directo en el celular):
   ```powershell
   eas build -p android --profile preview
   ```
4. **Versión para Google Play** (`.aab`):
   ```powershell
   eas build -p android --profile production
   ```
   La primera vez súbelo a mano en Google Play Console (Prueba interna → Crear versión). Las siguientes pueden ir con `eas submit -p android`.

> El identificador `com.brayansmith.balances` (en `app.json`) **no se puede cambiar** después de publicar en Google Play. Si quieres otro, cámbialo antes del primer envío.

## 3. Pasar tus datos locales a producción (opcional)

Las migraciones crean la base vacía. Si quieres llevarte lo que tienes en Laragon:

1. **Base de datos:** exporta `balances` desde HeidiSQL (solo datos, sin la tabla `migrations`) e impórtala en la MySQL de Cloud con los datos de conexión de su panel.
2. **Archivos:** sube el contenido de `storage/app/private/` (carpetas `adjuntos/` y `fotos/`) al bucket, manteniendo las mismas rutas.
