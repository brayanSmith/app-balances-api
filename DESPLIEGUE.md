# Despliegue

- **API (este repo):** Laravel Cloud.
- **App móvil (`app-balances-mobile`):** EAS Build + Google Play.

## 1. API en Laravel Cloud

### Crear la aplicación

1. En [cloud.laravel.com](https://cloud.laravel.com), **New application** → conecta GitHub y elige `brayanSmith/app-balances-api`, rama `main`.
2. Elige la región más cercana a tus usuarios (para Colombia, una de Estados Unidos este).

### Recursos del entorno

| Recurso            | Qué hacer                                                                                                                                                                       |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Base de datos**  | Crea una **MySQL** y conéctala al entorno. Cloud agrega solo las variables `DB_*`.                                                                                              |
| **Almacenamiento** | Crea un **bucket privado** y conéctalo. Nombre del disco: `archivos`, y marca **"Use as default disk"**. Ahí se guardan comprobantes, fotos de perfil y los ZIP de importación. |
| **Scheduler**      | En el cómputo de la app, activa el **Scheduler**: borra cada noche los ZIP de importación que no se usaron (`importaciones:limpiar`).                                           |

> Si el bucket no queda como disco por defecto, los archivos se guardan en el disco del servidor y **se pierden en cada despliegue**.

### Comandos

- **Build:** `composer install --no-dev --optimize-autoloader`, luego `npm ci` y `npm run build` (el panel `/admin` es React; el build genera las rutas de Wayfinder con `php artisan`, por eso va después de Composer).
- **Deploy:** `php artisan migrate --force`

### Variables de entorno

`APP_KEY` y `APP_URL` las pone Cloud. Agrega:

```dotenv
APP_NAME=Balances
APP_ENV=production
APP_DEBUG=false
APP_LOCALE=es
APP_FALLBACK_LOCALE=es
# Si no, "hoy" cambia a las 7 p. m. de Colombia (vencimientos, historial, proyección).
APP_TIMEZONE=America/Bogota

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

1. Instala y enlaza EAS (una sola vez):
    ```powershell
    npm install -g eas-cli
    cd C:\laragon\www\balances\mobile
    eas login
    eas init        # crea el proyecto en expo.dev y agrega su id a app.json
    ```
2. La URL de la API y el ID de cliente de Google son **variables de entorno de EAS** (expo.dev → proyecto → Environment variables), en los entornos `development`, `preview` y `production`. Se usan al compilar **y** al publicar actualizaciones. Para cambiarlas:
    ```powershell
    eas env:create --name EXPO_PUBLIC_API_URL --value https://TU-API/api --environment development --environment preview --environment production --visibility plaintext --force
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

## 4. Iniciar sesión con Google

La app usa el selector de cuentas nativo de Android y la API verifica con Google el _ID token_ que recibe. Hay que crear dos credenciales en Google Cloud (gratis).

### En Google Cloud Console

1. En [console.cloud.google.com](https://console.cloud.google.com), crea un proyecto (por ejemplo "Balances").
2. **APIs y servicios → Pantalla de consentimiento de OAuth** (o **Google Auth Platform**):
    - Tipo **Externo**, nombre "Balances", tu correo de soporte.
    - Alcances: los básicos (`email`, `profile`, `openid`), que no necesitan revisión de Google.
    - Mientras esté en modo **Prueba**, solo pueden entrar los correos que agregues como usuarios de prueba. Publícala cuando la app salga a producción.
3. **Credenciales → Crear credenciales → ID de cliente de OAuth**, dos veces:
    - **Web** (nombre "Balances API"). Copia su **ID de cliente**: es el que usan la app y la API.
    - **Android**:
        - Nombre del paquete: `com.brayansmith.balances`.
        - Huella **SHA-1** de la llave con la que EAS firma la app. Está en [expo.dev](https://expo.dev/accounts/brayansmith/projects/balances/credentials) → Credentials → Android → `com.brayansmith.balances`, o en `eas credentials -p android`.
        - Cuando publiques en Google Play, crea **otro** cliente Android con la SHA-1 de _App signing_ de Play Console (Configuración → Integridad de la app): Google vuelve a firmar la app con su propia llave.

### Configurar

- **Laravel Cloud** → variables de entorno, y vuelve a desplegar:
    ```dotenv
    GOOGLE_CLIENT_IDS=TU_ID_DE_CLIENTE_WEB.apps.googleusercontent.com
    ```
- **App** → variable de EAS `EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID` con el mismo ID de cliente **Web** (como en el paso 2 de la sección 2). Sin ese valor el botón no aparece.

> El botón no funciona en Expo Go (es un módulo nativo); se prueba con el APK de EAS.
>
> Para iPhone hará falta un cliente iOS y agregar el plugin `@react-native-google-signin/google-signin` en `app.json` con su `iosUrlScheme`.

## 5. Probar cambios sin volver a compilar

### Development build (día a día)

Es un APK que se compila **una vez** y carga el código en vivo desde tu PC. Incluye los módulos nativos (Google, notificaciones, fotos), a diferencia de Expo Go.

1. Compílalo e instálalo (una vez, o cuando se agregue una librería nativa nueva):
    ```powershell
    eas build -p android --profile development
    ```
2. En la PC, con el celular en la **misma red Wi-Fi**:
    ```powershell
    cd C:\laragon\www\balances\mobile
    npx expo start
    ```
3. Abre **Balances** en el celular y elige tu PC, o escanea el QR de la terminal. Cada cambio en el código se ve en segundos.

> El development build y el APK de prueba son la misma app (`com.brayansmith.balances`): instalar uno reemplaza al otro. Para volver a probar como un usuario normal, reinstala el APK de prueba.

La API a la que apunta sale de tu `mobile/.env` (`EXPO_PUBLIC_API_URL`): la de Laragon (`http://IP-DE-TU-PC:8001/api`) o la de producción. Si cambias el `.env`, reinicia `npx expo start`.

### EAS Update (arreglos para quien ya tiene la app)

Publica el código nuevo "por el aire" a los APK de prueba o a la versión de Google Play, sin compilar ni pasar por la fila:

```powershell
cd C:\laragon\www\balances\mobile
npm run actualizar:prueba -- "Arreglo del historial"        # APK de prueba (canal preview)
npm run actualizar:produccion -- "Arreglo del historial"    # Google Play (canal production)
```

La app la descarga al abrirse y la aplica la siguiente vez que se abre.

Solo sirve para cambios de **JavaScript** (pantallas, lógica, estilos). Si se agrega una librería nativa, hay que compilar de nuevo: la app usa `runtimeVersion: fingerprint`, así que una actualización nunca llega a un APK que no tenga lo nativo que necesita.
