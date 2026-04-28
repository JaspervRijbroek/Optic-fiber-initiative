/**
 * POST /api/register
 *
 * Body (JSON):
 *   { nombre: string, contacto: string, cru: string }
 *
 * Responses:
 *   201  { success: true }
 *   400  { error: string }   – missing / invalid fields
 *   409  { error: string }   – CRU already registered
 *   500  { error: string }   – server error
 *
 * Cloudflare D1 binding: DB  (configure in wrangler.toml / dashboard)
 */

const CORS_HEADERS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'POST, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type',
};

function json(body, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...CORS_HEADERS },
  });
}

/** Basic sanitisation – strip leading/trailing whitespace */
function sanitise(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function isValidEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function isValidPhone(value) {
  return /^[\d\s\-\+]{7,15}$/.test(value);
}

export async function onRequestOptions() {
  return new Response(null, { status: 204, headers: CORS_HEADERS });
}

export async function onRequestPost({ request, env }) {
  let body;
  try {
    body = await request.json();
  } catch {
    return json({ error: 'Cuerpo de la solicitud no válido.' }, 400);
  }

  const nombre   = sanitise(body.nombre);
  const contacto = sanitise(body.contacto);
  const cru      = sanitise(body.cru).toUpperCase();

  // ── Validation ──────────────────────────────────────────────
  if (!nombre) {
    return json({ error: 'El campo "nombre" es obligatorio.' }, 400);
  }
  if (!contacto || (!isValidEmail(contacto) && !isValidPhone(contacto))) {
    return json({ error: 'Introduce un correo electrónico o teléfono válido.' }, 400);
  }
  if (!cru) {
    return json({ error: 'El campo "CRU" es obligatorio.' }, 400);
  }

  // ── Duplicate check ─────────────────────────────────────────
  try {
    const existing = await env.DB
      .prepare('SELECT id FROM registrations WHERE cru = ?')
      .bind(cru)
      .first();

    if (existing) {
      return json({ error: 'Este CRU ya está registrado.' }, 409);
    }
  } catch (err) {
    console.error('DB duplicate check error:', err);
    return json({ error: 'Error del servidor al verificar el CRU.' }, 500);
  }

  // ── Insert ───────────────────────────────────────────────────
  try {
    await env.DB
      .prepare(
        'INSERT INTO registrations (nombre, contacto, cru) VALUES (?, ?, ?)'
      )
      .bind(nombre, contacto, cru)
      .run();

    return json({ success: true }, 201);
  } catch (err) {
    console.error('DB insert error:', err);
    return json({ error: 'Error del servidor al guardar el registro.' }, 500);
  }
}
