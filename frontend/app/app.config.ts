/**
 * Identity of the application and its own menu entries: the rest of the interface (layout, dashboard,
 * administration pages) is shared by every Rocket application.
 */
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'orange',
      neutral: 'zinc',
    },
  },
  rocket: {
    id: 'mailer',
    name: 'Rocket Mailer',
    icon: 'i-lucide-send',
    // Login page subtitle.
    tagline: 'Écrivez et envoyez vos emails, depuis le navigateur ou vos applications.',
    // Main menu: the domain pages ("label" entries start a group).
    navigation: [
      { label: 'Messagerie', type: 'label' },
      { label: 'Nouveau message', icon: 'i-lucide-send', to: '/compose' },
      { label: 'Mes envois', icon: 'i-lucide-inbox', to: '/emails', exact: true },
      { label: 'Tous les envois', icon: 'i-lucide-mails', to: '/emails/all', admin: true },
      { label: 'Templates', icon: 'i-lucide-layout-template', to: '/templates' },
    ] as { label: string, icon?: string, to?: string, type?: 'label', exact?: boolean, exactQuery?: boolean, admin?: boolean }[],
    // The compose UI is embedded by other applications (/embed/compose, public/embed.js).
    embed: true,
    // Extra entries of the Administration menu.
    adminNavigation: [
      { label: 'Boîtes d’envoi', icon: 'i-lucide-mailbox', to: '/mailboxes' },
      { label: 'Layouts d’email', icon: 'i-lucide-panels-top-left', to: '/layouts' },
      { label: 'Réglages', icon: 'i-lucide-settings', to: '/settings' },
    ] as { label: string, icon: string, to: string, exactQuery?: boolean }[],
    // "Services & raccourcis" of the dashboard, besides the documentation, changelog and API (Mailpit: plugins/mailpit.ts).
    shortcuts: [
      { label: 'Templates', description: 'Éditeur visuel GrapesJS', icon: 'i-lucide-layout-template', to: '/templates' },
      { label: 'Réglages', description: 'Adresses d’expédition', icon: 'i-lucide-settings', to: '/settings', admin: true },
    ] as { label: string, description: string, icon: string, to: string, admin?: boolean }[],
    // Hero banner of the dashboard: one quote per day.
    quotes: [
      ['Le meilleur email est celui qu’on a envie de lire jusqu’au bout.', 'Proverbe du support'],
      ['Écrire, c’est une façon de parler sans être interrompu.', 'Jules Renard'],
      ['La simplicité est la sophistication suprême.', 'Léonard de Vinci'],
      ['Ce qui se conçoit bien s’énonce clairement.', 'Nicolas Boileau'],
    ] as [string, string][],
  },
})
