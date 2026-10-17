-- Tablas del endpoint central de licencias (site/api/licencia.php).
-- Ejecútalo una vez en hPanel → Bases de datos → phpMyAdmin → pestaña SQL.
-- Nunca se guarda la clave: solo su hash SHA-256 (64 caracteres hex).

-- Dispositivos activados por clave y por app (también los usa Biuan con app = 'biuan').
CREATE TABLE IF NOT EXISTS licencia_dispositivos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave_hash CHAR(64) NOT NULL,
  app VARCHAR(32) NOT NULL,
  dispositivo VARCHAR(64) NOT NULL,
  alta DATETIME NOT NULL,
  ultimo_uso DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY clave_app_dispositivo (clave_hash, app, dispositivo),
  KEY clave_dispositivo (clave_hash, dispositivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Caché de 24 h de la respuesta de Gumroad, resumida (sin clave, sin correo del comprador).
CREATE TABLE IF NOT EXISTS licencia_cache (
  clave_hash CHAR(64) NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  resultado TEXT NOT NULL,
  consultado DATETIME NOT NULL,
  PRIMARY KEY (clave_hash, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Límite de peticiones por IP (llave = SHA-256 de grupo + IP).
CREATE TABLE IF NOT EXISTS licencia_limites (
  llave CHAR(64) NOT NULL,
  inicio INT UNSIGNED NOT NULL,
  conteo INT UNSIGNED NOT NULL,
  PRIMARY KEY (llave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
