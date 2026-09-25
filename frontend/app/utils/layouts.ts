// Same rules as the API (App\Template\Layouts): the template content goes in the layout's {{ content }} slot.
const SLOT = /\{\{\s*content\s*\}\}/

export function hasSlot(layoutHtml: string): boolean {
  return SLOT.test(layoutHtml)
}

/** Body of a full document (GrapesJS exports <body>…</body>), or the fragment itself. */
export function bodyOf(html: string): string {
  const match = html.match(/<body\b[^>]*>([\s\S]*)<\/body>/i)
  if (match) return match[1]!.trim()
  return html.replace(/<head\b[\s\S]*?<\/head>/i, '').replace(/<\/?(?:html|head|body)\b[^>]*>/gi, '').trim()
}

export function wrapInLayout(layoutHtml: string, contentHtml: string): string {
  const content = bodyOf(contentHtml)
  return layoutHtml.replace(SLOT, () => content)
}

/** Starting points for a new layout (email-safe: tables, inline styles, 600 px). */
export const LAYOUT_STARTERS: { id: string, label: string, html: string }[] = [
  {
    id: 'simple',
    label: 'Sobre (logo, contenu, pied de page)',
    html: `<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f5;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
        <tr><td style="padding:24px 32px;border-bottom:1px solid #e4e4e7;font-size:20px;font-weight:bold;">Votre organisation</td></tr>
        <tr><td style="padding:32px;font-size:15px;line-height:1.6;">{{ content }}</td></tr>
        <tr><td style="padding:16px 32px;background:#fafafa;color:#71717a;font-size:12px;border-radius:0 0 8px 8px;">Votre organisation · 1 rue de l'Exemple, 75000 Paris</td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>`,
  },
  {
    id: 'banner',
    label: 'Bandeau de couleur',
    html: `<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#eef2ff;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2ff;padding:32px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#1e1b4b;">
        <tr><td style="background:#4338ca;padding:28px 32px;color:#ffffff;font-size:22px;font-weight:bold;">Votre organisation</td></tr>
        <tr><td style="padding:32px;font-size:15px;line-height:1.6;">{{ content }}</td></tr>
        <tr><td style="padding:20px 32px;border-top:1px solid #e0e7ff;color:#6366f1;font-size:12px;">Vous recevez cet email en tant que client. <a href="https://exemple.com" style="color:#4338ca;">exemple.com</a></td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>`,
  },
]

export const LAYOUT_PREVIEW_CONTENT = '<h1 style="margin:0 0 12px;font-size:22px;">Titre du message</h1><p style="margin:0 0 12px;">Bonjour {{ client.prenom }},</p><p style="margin:0;">Le contenu de chaque template s’affiche ici, à la place de <code>{{ content }}</code>.</p>'
