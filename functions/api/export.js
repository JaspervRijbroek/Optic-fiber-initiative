/**
 * GET /api/export?token=<EXPORT_SECRET>
 *
 * Returns a CSV file with all registrations.
 * Protected by a secret token stored in Cloudflare Pages environment variables.
 *
 * Set up in Cloudflare dashboard (or wrangler.toml [vars]):
 *   EXPORT_SECRET = "a-long-random-secret-string"
 *
 * Cloudflare D1 binding: DB
 */

const CORS_HEADERS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET, OPTIONS',
  'Access-Control-Allow-Headers': 'Authorization',
};

function unauthorized() {
  return new Response('No autorizado. Proporciona el token correcto.', {
    status: 401,
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      'WWW-Authenticate': 'Bearer realm="Exportar registros"',
      ...CORS_HEADERS,
    },
  });
}

/** Escape a CSV field value: wrap in quotes and escape internal quotes */
function csvField(value) {
  const str = value == null ? '' : String(value);
  return '"' + str.replace(/"/g, '""') + '"';
}

export async function onRequestOptions() {
  return new Response(null, { status: 204, headers: CORS_HEADERS });
}

export async function onRequestGet({ request, env }) {
  const url   = new URL(request.url);
  const token = url.searchParams.get('token') || '';

  // ── Authentication (token checked first, before any other logic) ────────────
  const exportSecret = env.EXPORT_SECRET;

  if (!exportSecret) {
    console.error('EXPORT_SECRET environment variable is not configured.');
    return new Response('Configuración del servidor incompleta.', { status: 500 });
  }

  // Constant-time comparison to prevent timing attacks
  if (!timingSafeEqual(token, exportSecret)) {
    return unauthorized();
  }

  // ── Fetch all registrations ─────────────────────────────────
  let results;
  try {
    const stmt = await env.DB
      .prepare('SELECT id, nombre, contacto, cru, created_at FROM registrations ORDER BY created_at ASC')
      .all();
    results = stmt.results;
  } catch (err) {
    console.error('DB export error:', err);
    return new Response('Error al obtener los registros.', {
      status: 500,
      headers: { 'Content-Type': 'text/plain; charset=utf-8' },
    });
  }

  // ── Build CSV ────────────────────────────────────────────────
  const header = ['ID', 'Nombre', 'Contacto', 'CRU', 'Fecha de Registro'].map(csvField).join(',');
  const rows = results.map((row) =>
    [row.id, row.nombre, row.contacto, row.cru, row.created_at].map(csvField).join(',')
  );

  const csv = [header, ...rows].join('\r\n');

  const filename = `registros-fibra-optica-${new Date().toISOString().slice(0, 10)}.csv`;

  return new Response('\uFEFF' + csv, {   // BOM for Excel compatibility
    status: 200,
    headers: {
      'Content-Type': 'text/csv; charset=utf-8',
      'Content-Disposition': `attachment; filename="${filename}"`,
      ...CORS_HEADERS,
    },
  });
}

/**
 * Constant-time string comparison to mitigate timing-based token guessing.
 * Returns true only if both strings are identical and non-empty.
 */
function timingSafeEqual(a, b) {
  if (!a || !b || a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) {
    diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return diff === 0;
}
