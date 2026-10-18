# Licencias: endpoint central

`site/api/licencia.php` verifica las claves de Gumroad para Cuadra, CalificaYa y Biuan.
Se publica solo con el resto de `site/` (GitHub Actions → FTP). Lo que **no** se publica
(configuración y contraseña de la base de datos) va en una carpeta privada que creas a mano.

## Primera vez en Hostinger

1. **Base de datos.** hPanel → Bases de datos → MySQL: crea una base y un usuario.
   Abre phpMyAdmin, pestaña **SQL**, y pega el contenido de `servidor/schema.sql`.
2. **Carpeta privada.** Con el Administrador de archivos, crea `trucosdeaula-privado/`
   **al lado** de `public_html` (no adentro):
   ```
   domains/trucosdeaula.com/
   ├─ public_html/            ← lo sube GitHub Actions (incluye api/licencia.php)
   └─ trucosdeaula-privado/   ← a mano
      ├─ .htaccess            ← copia de servidor/privado/.htaccess
      └─ config.php           ← copia de servidor/privado/config.ejemplo.php, ya llena
   ```
3. **config.php.** Llena los datos de la base y reemplaza `ID_CUADRA`, `ID_CALIFICAYA`,
   `ID_BIUAN` e `ID_PASE` por los product_id reales (Gumroad → producto → Content →
   License key). Si la carpeta privada quedara en otro lugar, define la variable de entorno
   `TRUCOS_PRIVADO` con su ruta.
4. Prueba: `curl -X POST https://trucosdeaula.com/api/licencia.php -H 'Content-Type: application/json' -d '{"accion":"verificar","app":"cuadra","licenseKey":"TU-CLAVE","deviceId":"prueba-0001"}'`
   y luego libera ese lugar con `"accion":"liberar"`.

## Cómo funciona

- **verificar** `{ accion, app, licenseKey, deviceId }` → `{ valida, tipo, vigenteHasta, dispositivosUsados, motivo }`.
  Prueba la clave contra cada product_id que acepta la app (`apps` en config.php).
- **liberar** `{ accion, licenseKey, deviceId }` → `{ liberado, motivo }`.
- Se rechaza si la compra está reembolsada, con contracargo o en disputa. En el pase, también si
  tiene pago fallido o si su fecha de fin o de cancelación ya llegó (hoy o antes, hora de México).
  Una cancelación con fecha futura sigue valiendo hasta esa fecha (`vigenteHasta`).
- Dispositivos: hasta `max_dispositivos` por clave **y por app** (el pase en Cuadra y en
  CalificaYa cuenta aparte). No se usa el contador `uses` de Gumroad.
- Caché de 24 h por hash de la clave y producto. Si Gumroad no contesta en 3 s: error 503
  `gumroad_no_disponible` y no se registra ningún dispositivo nuevo.
- La clave nunca se guarda ni se registra: en MySQL solo va su SHA-256.

## Liberar dispositivos a mano

En phpMyAdmin (pon la clave en mayúsculas, sin espacios):
```sql
SELECT app, dispositivo, alta, ultimo_uso FROM licencia_dispositivos
 WHERE clave_hash = SHA2('LA-CLAVE-DEL-CLIENTE', 256);
DELETE FROM licencia_dispositivos
 WHERE clave_hash = SHA2('LA-CLAVE-DEL-CLIENTE', 256) AND app = 'cuadra';
```

## Pruebas

Necesitan PHP 8 con pdo_mysql y curl, y una base MySQL/MariaDB **solo para pruebas**
(el script borra y crea sus tablas):
```
PRUEBAS_DSN='mysql:host=127.0.0.1;dbname=trucos_pruebas;charset=utf8mb4' \
PRUEBAS_USUARIO=trucos PRUEBAS_CLAVE=... php servidor/pruebas/probar.php
```
Levanta un Gumroad simulado (`servidor/pruebas/gumroad_simulado.php`) y el sitio con
`php -S`, y revisa todos los casos: claves de app y de pase, Biuan fuera del pase,
cancelaciones, pago fallido, reembolsos, límite de dispositivos, liberar, caché, Gumroad
caído o lento, CORS, rate limit y que no quede ninguna clave en texto plano.
