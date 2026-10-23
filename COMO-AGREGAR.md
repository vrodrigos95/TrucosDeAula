# Cómo agregar contenido

Todo sale de dos archivos; no toques `site/index.html` (el deploy lo regenera):
- `site/data/proyectos.json` → proyectos · `site/data/contenido.json` → juegos, infografías, tips y Pase Maestro.

Copia un elemento existente, cambia sus campos, haz commit y `git push` a `main`. GitHub Actions regenera la página, corre `npm run verificar` y sube por FTP. Si el verificador falla, no se publica nada: mira el log.

**Proyecto** (`proyectos.json`; se muestran disponibles primero, agrupados por categoría):
- `nombre`, `descripcion`, `paraQuien`: textos de la tarjeta. `categoria`: grupo (ej. "Juegos").
- `estado`: `"disponible"`, `"gratis"` o `"proximamente"` (próximamente = sin botones).
- `ligaPrincipal`: obligatoria si está disponible. `ligaCompra`: botón azul "Comprar" (opcional).
- `precio`: se añade al botón ("Comprar · $99"). `imagen`: URL de fondo (opcional).

**Juego** (`contenido.json` → `juegos`): `title`, `desc`, `etiqueta` (ej. "Gratis · en el navegador"), `url`, `sym` (símbolo del círculo) y `chip` (color del círculo).

**Infografía** (`contenido.json` → `infografias`): `num`, `title`, `desc`, `slug`, `url`, `min` (minutos de lectura), `bg` (color), `basis`/`offset` (tamaño y desfase en la rejilla).
Sin `url` no aparece la tarjeta: sale en la línea "Próximamente: …".

**Pase Maestro**: `pase.url` = liga de compra (vacía → botón "Avísame cuando salga"); `pase.incluye` = lista de lo que incluye.

Nunca dejes ligas con `PLACEHOLDER`: déjalas en `""` hasta tenerlas.
Para revisar en tu compu antes de subir: `node scripts/prerender.mjs && npm run verificar`.
