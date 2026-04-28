/**
 * POST /api/register
 *
 * Body (JSON):
 *   { nombre: string, email: string, cru: string, turnstileToken: string }
 *
 * Responses:
 *   201  { success: true }
 *   400  { error: string }   – missing / invalid fields or failed Turnstile check
 *   409  { error: string }   – CRU already registered
 *   500  { error: string }   – server error
 *
 * Environment variables (set in Cloudflare Pages dashboard):
 *   DB                   – D1 database binding (wrangler.toml)
 *   TURNSTILE_SECRET_KEY – Cloudflare Turnstile secret key (skip check if absent)
 *   RESEND_API_KEY       – Resend email API key (skip email if absent)
 *   FROM_EMAIL           – Verified sender address for outgoing emails
 *   SITE_URL             – Public site URL used to build the unsubscribe link
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

/** Verify a Cloudflare Turnstile token server-side. Returns true if valid. */
async function verifyTurnstile(token, secretKey, ip) {
  if (!secretKey) return true; // Skip verification when not configured
  const form = new FormData();
  form.append('secret', secretKey);
  form.append('response', token);
  if (ip) form.append('remoteip', ip);
  const res = await fetch('https://challenges.cloudflare.com/turnstile/v1/siteverify', {
    method: 'POST',
    body: form,
  });
  const data = await res.json();
  return data.success === true;
}

/** Generate a cryptographically random hex token for unsubscribe links. */
function generateToken() {
  const bytes = new Uint8Array(24);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

/** Send a confirmation email via Resend. Skipped when RESEND_API_KEY is absent. */
async function sendConfirmationEmail(env, { nombre, email, unsubscribeToken }) {
  if (!env.RESEND_API_KEY) return;

  const siteUrl = (env.SITE_URL || '').replace(/\/$/, '');
  const unsubscribeUrl = `${siteUrl}/api/unsubscribe?token=${unsubscribeToken}`;
  const firstName = nombre.split(' ')[0];

  const html = `<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:system-ui,sans-serif;background:#f0f4ff;margin:0;padding:24px">
  <div style="background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.1);padding:40px;max-width:480px;margin:0 auto">
    <div style="text-align:center;margin-bottom:24px">
      <svg width="56" height="56" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="28" cy="28" r="28" fill="#e8eaf6"/>
        <path d="M14 28 Q21 14 28 28 Q35 42 42 28" stroke="#1a237e" stroke-width="2.5" fill="none" stroke-linecap="round"/>
        <path d="M14 22 Q21 8 28 22 Q35 36 42 22" stroke="#3949ab" stroke-width="2" fill="none" stroke-linecap="round" opacity=".6"/>
        <path d="M14 34 Q21 20 28 34 Q35 48 42 34" stroke="#3949ab" stroke-width="2" fill="none" stroke-linecap="round" opacity=".6"/>
        <circle cx="14" cy="28" r="3" fill="#1a237e"/>
        <circle cx="42" cy="28" r="3" fill="#1a237e"/>
      </svg>
    </div>
    <h1 style="color:#1a237e;text-align:center;font-size:22px;margin:0 0 8px">¡Gracias por registrarte, ${firstName}!</h1>
    <p style="color:#546e7a;text-align:center;font-size:15px;line-height:1.6;margin:0 0 24px">
      Hemos recibido tu interés en recibir fibra óptica en tu zona.<br>
      Cuantas más personas se registren, más rápido podremos avanzar con la instalación.
    </p>
    <div style="background:#e8eaf6;border-radius:12px;padding:16px 20px;margin-bottom:24px">
      <p style="color:#1a237e;font-size:14px;margin:0">
        📋 <strong>¿Qué pasa ahora?</strong> Recopilaremos todos los registros y los
        enviaremos formalmente a Telefónica para demostrar la demanda en tu zona.
        Te contactaremos cuando haya novedades.
      </p>
    </div>
    <p style="color:#546e7a;font-size:13px;text-align:center;margin:0">
      ¿Conoces a alguien más que quiera fibra óptica? ¡Comparte esta iniciativa!
    </p>
  </div>
  <p style="text-align:center;margin-top:24px;font-size:11px;color:#90a4ae">
    Si no deseas recibir más comunicaciones de esta iniciativa, puedes
    <a href="${unsubscribeUrl}" style="color:#546e7a">darte de baja aquí</a>.
  </p>
</body>
</html>`;

  await fetch('https://api.resend.com/emails', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${env.RESEND_API_KEY}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      from: env.FROM_EMAIL || 'Fibra Óptica Torrent <noreply@fibra-torrent.es>',
      to: [email],
      subject: '¡Gracias por registrar tu interés en fibra óptica!',
      html,
    }),
  });
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

  const nombre         = sanitise(body.nombre);
  const email          = sanitise(body.email);
  const cru            = sanitise(body.cru).toUpperCase();
  const turnstileToken = sanitise(body.turnstileToken);

  // ── Turnstile verification ───────────────────────────────────
  const ip = request.headers.get('CF-Connecting-IP');
  let turnstileOk;
  try {
    turnstileOk = await verifyTurnstile(turnstileToken, env.TURNSTILE_SECRET_KEY, ip);
  } catch {
    turnstileOk = false;
  }
  if (!turnstileOk) {
    return json({ error: 'Verificación de seguridad fallida. Por favor, inténtalo de nuevo.' }, 400);
  }

  // ── Validation ──────────────────────────────────────────────
  if (!nombre) {
    return json({ error: 'El campo "nombre" es obligatorio.' }, 400);
  }
  if (!email || !isValidEmail(email)) {
    return json({ error: 'Introduce un correo electrónico válido.' }, 400);
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
  const unsubscribeToken = generateToken();

  try {
    await env.DB
      .prepare(
        'INSERT INTO registrations (nombre, email, cru, unsubscribe_token) VALUES (?, ?, ?, ?)'
      )
      .bind(nombre, email, cru, unsubscribeToken)
      .run();
  } catch (err) {
    console.error('DB insert error:', err);
    return json({ error: 'Error del servidor al guardar el registro.' }, 500);
  }

  // ── Send confirmation email (fire-and-forget) ────────────────
  sendConfirmationEmail(env, { nombre, email, unsubscribeToken }).catch((err) => {
    console.error('Email send error:', err);
  });

  return json({ success: true }, 201);
}
