/**
 * GET /api/unsubscribe?token=<unsubscribe_token>
 *
 * Deletes the matching registration from the database (GDPR-compliant removal)
 * and returns an HTML confirmation page styled with Tailwind CSS.
 *
 * Cloudflare D1 binding: DB
 */

export async function onRequestGet({ request, env }) {
  const url   = new URL(request.url);
  const token = (url.searchParams.get('token') || '').trim();

  if (!token) {
    return page('Enlace no válido', '<p>El enlace de baja no es válido.</p>', 400);
  }

  let row;
  try {
    row = await env.DB
      .prepare('SELECT id FROM registrations WHERE unsubscribe_token = ?')
      .bind(token)
      .first();
  } catch (err) {
    console.error('DB unsubscribe lookup error:', err);
    return page('Error del servidor', '<p>Ha ocurrido un error. Por favor, inténtalo de nuevo.</p>', 500);
  }

  if (!row) {
    // Row not found: either the token is invalid or the data was already deleted
    return page(
      'Solicitud procesada',
      '<p>Tus datos ya han sido eliminados de nuestra lista o el enlace no es válido.</p>',
    );
  }

  try {
    await env.DB
      .prepare('DELETE FROM registrations WHERE unsubscribe_token = ?')
      .bind(token)
      .run();
  } catch (err) {
    console.error('DB unsubscribe delete error:', err);
    return page('Error del servidor', '<p>Ha ocurrido un error al procesar tu solicitud.</p>', 500);
  }

  return page(
    '¡Baja confirmada!',
    `<p>Hemos eliminado todos tus datos de nuestra lista. No recibirás más comunicaciones de esta iniciativa.</p>
     <p class="mt-4"><a href="/" class="font-semibold text-[#1a237e] hover:underline">Volver al inicio</a></p>`,
  );
}

/**
 * Build a simple HTML response page.
 * NOTE: `bodyContent` must be a trusted string literal — never pass
 * user-supplied data here as it is interpolated directly into HTML.
 */
function page(title, bodyContent, status = 200) {
  const html = `<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>${title} — Fibra Óptica Torrent</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center p-6 bg-gradient-to-br from-blue-50 to-green-50 font-[system-ui,sans-serif]">
  <div class="bg-white rounded-2xl shadow-xl p-10 w-full max-w-md text-center">
    <h1 class="text-2xl font-bold text-[#1a237e] mb-4">${title}</h1>
    <div class="text-slate-500 leading-relaxed">${bodyContent}</div>
  </div>
</body>
</html>`;

  return new Response(html, {
    status,
    headers: { 'Content-Type': 'text/html; charset=utf-8' },
  });
}
