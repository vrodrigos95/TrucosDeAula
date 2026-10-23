// Revisa los datos del sitio antes de subirlo (Node 20, sin dependencias).
//
//   npm run verificar
//
// Saca el JSON de <script id="datos-sitio"> de site/index.html y falla (exit 1) si:
//   - algún texto contiene "PLACEHOLDER";
//   - un proyecto "disponible" no tiene ligaPrincipal;
//   - alguna liga (ligaPrincipal, ligaCompra, url) no responde 200 tras redirecciones.

import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const raiz = join(dirname(fileURLToPath(import.meta.url)), "..");
const html = readFileSync(join(raiz, "site/index.html"), "utf8");
const m = html.match(/<script id="datos-sitio" type="application\/json">([\s\S]*?)<\/script>/);
if (!m) {
  console.error("No encontré <script id=\"datos-sitio\"> en site/index.html");
  process.exit(1);
}
const datos = JSON.parse(m[1]);
const errores = [];

// Recorre todo el JSON: textos con PLACEHOLDER y ligas a revisar.
const ligas = [];
const CAMPOS_LIGA = ["ligaPrincipal", "ligaCompra", "url"];
function recorrer(v, ruta, dueño) {
  if (typeof v === "string") {
    if (v.includes("PLACEHOLDER")) errores.push(`${ruta} contiene PLACEHOLDER`);
  } else if (Array.isArray(v)) {
    v.forEach((x, i) => recorrer(x, `${ruta}[${i}]`, dueño));
  } else if (v && typeof v === "object") {
    const nombre = v.nombre || v.title || dueño;
    for (const [k, x] of Object.entries(v)) {
      if (CAMPOS_LIGA.includes(k) && typeof x === "string" && x && !x.includes("PLACEHOLDER")) ligas.push({ nombre, campo: k, url: x });
      recorrer(x, ruta ? `${ruta}.${k}` : k, ruta ? nombre : k);
    }
  }
}
recorrer(datos, "", "sitio");

for (const p of datos.proyectos || []) {
  if (p.estado === "disponible" && !p.ligaPrincipal) errores.push(`Proyecto "${p.nombre}" está disponible pero no tiene ligaPrincipal`);
}

async function codigo(url) {
  for (let intento = 0; intento < 2; intento++) {
    try {
      const r = await fetch(url, { redirect: "follow", signal: AbortSignal.timeout(10_000), headers: { "user-agent": "Mozilla/5.0 (verificar-sitio trucosdeaula)" } });
      if (r.status === 200 || intento === 1) return r.status;
    } catch (e) {
      if (intento === 1) return e.name === "TimeoutError" ? "timeout" : e.cause?.code || e.cause?.message || e.message;
    }
  }
}

for (const l of ligas) {
  const c = await codigo(l.url);
  console.log(`${l.nombre} · ${c}  (${l.campo}: ${l.url})`);
  if (c !== 200) errores.push(`${l.nombre}: ${l.url} respondió ${c}`);
}

if (errores.length) {
  console.error("\n✗ " + errores.join("\n✗ "));
  process.exit(1);
}
console.log(`\n✓ Datos en orden (${ligas.length} ligas revisadas).`);
