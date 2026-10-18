// Genera el HTML estático de respaldo de site/index.html (sin dependencias).
//
//   node scripts/prerender.mjs          → reescribe site/index.html
//   node scripts/prerender.mjs --check  → falla si site/index.html no está al día
//
// 1. Junta site/data/contenido.json y site/data/proyectos.json y los mete en
//    <script id="datos-sitio" type="application/json"> (lo lee la clase Component).
// 2. Ejecuta la misma clase Component con su estado inicial, renderiza la plantilla
//    <x-dc> (sus {{ }}, <sc-for> y <sc-if>) y deja el resultado en <div id="prerender">.
//    Así el contenido existe en el HTML servido; al montar, Component lo quita.
//
// Solo se reescribe lo que está entre los marcadores <!-- prerender:… --> y <!-- datos:… -->.

import { readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const raiz = join(dirname(fileURLToPath(import.meta.url)), "..");
const rutaIndex = join(raiz, "site/index.html");
const leerJson = (r) => JSON.parse(readFileSync(join(raiz, r), "utf8"));

const html = readFileSync(rutaIndex, "utf8");
const datos = { ...leerJson("site/data/contenido.json"), proyectos: leerJson("site/data/proyectos.json") };

// --- Plantilla y clase -------------------------------------------------------

const entre = (txt, ini, fin) => {
  const a = txt.indexOf(ini);
  const b = txt.indexOf(fin, a + ini.length);
  if (a === -1 || b === -1) throw new Error(`No encontré ${ini} … ${fin} en site/index.html`);
  return txt.slice(a + ini.length, b);
};

let plantilla = entre(html, "<x-dc>", "</x-dc>");
const estilosHelmet = (plantilla.match(/<helmet>([\s\S]*?)<\/helmet>/) || [, ""])[1].trim();
plantilla = plantilla.replace(/<helmet>[\s\S]*?<\/helmet>/, "").trim();

const scriptClase = html.match(/<script type="text\/x-dc" data-dc-script[^>]*>([\s\S]*?)<\/script>/);
if (!scriptClase) throw new Error("No encontré el <script data-dc-script> en site/index.html");

const jsonDatos = JSON.stringify(datos).replace(/</g, "\\u003c");
const documentoFalso = { getElementById: (id) => (id === "datos-sitio" ? { textContent: jsonDatos } : null) };
class DCLogic {
  constructor(props) { this.props = props || {}; }
  setState() {}
}
const Component = new Function("DCLogic", "document", scriptClase[1] + "\n;return Component;")(DCLogic, documentoFalso);
const vals = new Component({}).renderVals();

// --- Mini intérprete de la plantilla (mismo subconjunto que usa support.js) ---

function resolver(ctx, expr) {
  expr = expr.trim();
  if (expr.startsWith("!")) return !resolver(ctx, expr.slice(1));
  if (expr === "true") return true;
  if (expr === "false") return false;
  if (expr === "null" || expr === "undefined") return undefined;
  if (/^-?\d+(\.\d+)?$/.test(expr)) return Number(expr);
  if (/^(['"]).*\1$/.test(expr)) return expr.slice(1, -1);
  return expr.split(".").reduce((v, k) => (v == null ? undefined : v[k]), ctx);
}

const escapar = (v) =>
  v === undefined || v === null || typeof v === "boolean" || typeof v === "function"
    ? ""
    : String(v).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

const interpolar = (txt, ctx) => txt.replace(/\{\{([\s\S]+?)\}\}/g, (_, e) => escapar(resolver(ctx, e)));

const atributo = (abre, nombre) => {
  const m = abre.match(new RegExp(`\\s${nombre}="\\{\\{([^}]*)\\}\\}"`)) || abre.match(new RegExp(`\\s${nombre}="([^"]*)"`));
  return m ? m[1] : "";
};

// Índice justo después del </tag> que cierra la etiqueta abierta en `desde`.
function cierre(txt, tag, desde) {
  const re = new RegExp(`<${tag}\\b|</${tag}>`, "g");
  re.lastIndex = desde;
  let nivel = 1;
  for (let m; (m = re.exec(txt)); ) {
    nivel += m[0][1] === "/" ? -1 : 1;
    if (nivel === 0) return { interior: txt.slice(desde, m.index), fin: re.lastIndex };
  }
  throw new Error(`<${tag}> sin cerrar en la plantilla`);
}

function renderizar(txt, ctx) {
  let salida = "";
  let i = 0;
  const re = /<(sc-for|sc-if)\b[^>]*>/g;
  for (let m; (re.lastIndex = i), (m = re.exec(txt)); ) {
    salida += interpolar(txt.slice(i, m.index), ctx);
    const { interior, fin } = cierre(txt, m[1], m.index + m[0].length);
    if (m[1] === "sc-for") {
      const lista = resolver(ctx, atributo(m[0], "list"));
      const como = atributo(m[0], "as") || "item";
      if (Array.isArray(lista)) lista.forEach((item, n) => (salida += renderizar(interior, { ...ctx, [como]: item, $index: n })));
    } else if (resolver(ctx, atributo(m[0], "value"))) {
      salida += renderizar(interior, ctx);
    }
    i = fin;
  }
  return salida + interpolar(txt.slice(i), ctx);
}

const estatico = renderizar(plantilla, vals)
  // Atributos que solo entiende el runtime (eventos, hover, pistas).
  .replace(/\s(?:on[A-Z]\w*|style-hover|style-focus|hint-[\w-]+)="[^"]*"/g, "")
  .replace(/\n\s*\n+/g, "\n");

// --- Escribir ----------------------------------------------------------------

const bloque = (nombre, contenido) => [`<!-- ${nombre}:inicio -->`, contenido, `<!-- ${nombre}:fin -->`].join("\n");
const reemplazar = (txt, nombre, contenido) => {
  const re = new RegExp(`<!-- ${nombre}:inicio -->[\\s\\S]*?<!-- ${nombre}:fin -->`);
  if (!re.test(txt)) throw new Error(`Faltan los marcadores <!-- ${nombre}:inicio/fin --> en site/index.html`);
  return txt.replace(re, () => bloque(nombre, contenido));
};

let nuevo = reemplazar(html, "prerender", `<div id="prerender">\n${estilosHelmet}\n${estatico}\n</div>`);
nuevo = reemplazar(nuevo, "datos", `<script id="datos-sitio" type="application/json">${jsonDatos}</script>`);

if (process.argv.includes("--check")) {
  if (nuevo !== html) {
    console.error("site/index.html no está al día: corre `node scripts/prerender.mjs` y sube el cambio.");
    process.exit(1);
  }
  console.log("site/index.html al día.");
} else {
  writeFileSync(rutaIndex, nuevo);
  console.log("site/index.html actualizado.");
}
