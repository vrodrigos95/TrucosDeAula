# Cómo agregar contenido

Todo sale de dos archivos; no toques `site/index.html` ni el `index.html` de `site/infografias/`, `site/proyectos/`, `site/juegos/`, `site/maestros/` y `site/tips/` (el deploy los regenera):
- `site/data/proyectos.json` → proyectos · `site/data/contenido.json` → retos, juegos, infografías, material, tips y tienda de maestros.
- Páginas: inicio (reto de 10 segundos y manifiesto), `/infografias/`, `/proyectos/`, `/juegos/`, `/maestros/` y `/tips/`.

Copia un elemento existente, cambia sus campos, haz commit y `git push` a `main`. GitHub Actions regenera la página, corre `npm run verificar` y sube por FTP. Si el verificador falla, no se publica nada: mira el log.

**Proyecto** (`proyectos.json`; se muestran disponibles primero, agrupados por categoría):
- `nombre`, `descripcion`, `paraQuien`: textos de la tarjeta. `categoria`: grupo (ej. "Juegos").
- `estado`: `"disponible"`, `"gratis"` o `"proximamente"` (próximamente = sin botones).
- `ligaPrincipal`: obligatoria si está disponible. `ligaCompra`: botón azul "Comprar" (opcional).
- `precio`: se añade al botón ("Comprar · $99"). `imagen`: URL de fondo (opcional).
- `pagina`: `"maestros"` para que también salga en /maestros/ (opcional).

**Juego** (`contenido.json` → `juegos`): `title`, `desc`, `etiqueta` (ej. "Gratis · en el navegador"), `url` (vacía → tarjeta "Próximamente"), `sym` (símbolo del círculo) y `chip` (color del círculo).
**Tip** (`contenido.json` → `tips`): `n` (número), `title`, `body`.
**Reto** (`contenido.json` → `retos`; el inicio muestra uno al azar en cada visita): `pregunta`, `opciones` (dos, cada una con `texto`, `dato` que se revela, `valor` numérico para el largo de la barra y `correcta: true` en la buena), `titulo` (la respuesta en una frase), `truco` (la explicación) y `ligas` (`texto` + `url`, por ejemplo a una infografía `/infografias/<slug>.pdf` o a `/tips/`).

**Infografía** (`contenido.json` → `infografias`): `num`, `tema`, `title`, `desc`, `slug`, `url`, `img`, `min` (minutos de lectura), `bg` (color del tema).
- El PDF va en `site/infografias/<slug>.pdf` y su miniatura en `site/infografias/miniaturas/<slug>.webp`; `url` e `img` apuntan ahí (`/infografias/…`).
- `tema` agrupa las tarjetas y crea su botón en el buscador (hoy: Álgebra, Geometría, Trigonometría, Probabilidad). Un tema nuevo aparece solo; usa el mismo `bg` en todas las de ese tema.
- Miniatura desde el PDF: `pdftoppm -f 1 -l 1 -r 60 -png <slug>.pdf t && convert t-1.png -resize 480x -gravity north -crop 480x600+0+0 +repage -quality 72 miniaturas/<slug>.webp`.
- El buscador encuentra por título, descripción, tema y slug, sin importar acentos.
Sin `url` no aparece la tarjeta: sale en la línea "Próximamente: …".

**Material** (`contenido.json` → `material`, sale en /infografias/ debajo de las infografías, en «Material para practicar»): páginas HTML interactivas. Mismos campos que una infografía menos `min` y `desc`, más `que` (qué es, una frase), `sirve` (para qué sirve, una frase), `tipo` (ej. "Interactivo", "Cuadernillo") y `pie` (la línea bajo la tarjeta, ej. "PDF · 2 páginas para imprimir").
- La página (o el PDF) va en `site/material/<slug>.html` (o `.pdf`) y su miniatura en `site/material/miniaturas/<slug>.webp`; `url` e `img` apuntan ahí (`/material/…`).
- Usa el mismo `tema` y `bg` que las infografías: comparte buscador y botones de tema.

**Tienda de maestros** (`contenido.json` → `tienda`, se ve en /maestros/):
- `productos`: cada app suelta con `id`, `nombre`, `precio` (número, en pesos), `periodo` (`"semestre"` o `"pago único"`), `estado`, `url` (la app) y `ligaCompra` (Gumroad). Sin `ligaCompra` el botón lleva a la app; con `estado: "proximamente"` sale «Próximamente».
- `paquetes`: `nombre`, `sub`, `lema`, `precio`, `incluye` (lista de `id` de productos), `extras` y `ligaCompra`. El precio tachado, el ahorro y el «≈ al mes» se calculan solos con los precios de `productos`. `destacado: true` + `sello` marca el recomendado (en celular sale primero).
- Sin `ligaCompra` el botón del paquete dice «Avísame cuando salga».
- `preguntas`: `p` (pregunta) y `r` (respuesta) del bloque de preguntas frecuentes.

Nunca dejes ligas con `PLACEHOLDER`: déjalas en `""` hasta tenerlas.
Para revisar en tu compu antes de subir: `node scripts/prerender.mjs && npm run verificar`.

**Clave del cuadernillo** (`/material/repaso-clave.html` + `site/api/clave.php`): 8 retos, uno por tema, con números al azar que genera el servidor (`site/api/_clave.php`). El navegador nunca recibe las respuestas. Si los resuelve, la sesión puede abrir `site/api/_privado/clave-repaso-algebra-geometria.pdf`, carpeta que `.htaccess` bloquea para acceso directo. Para cambiar la clave, reemplaza ese PDF.
