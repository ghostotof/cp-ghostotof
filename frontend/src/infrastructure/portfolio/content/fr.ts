import type { PortfolioLocaleContent } from './PortfolioLocaleContent'

const fr: PortfolioLocaleContent = {
  hero: {
    eyebrow: 'Développeur Web Senior',
    titleLead: 'Je construis des applications',
    titleAccent: 'robustes, performantes et évolutives.',
    description:
      'Développeur passionné par la création de solutions web modernes et maintenables, avec une approche orientée qualité et expérience utilisateur.',
    callsToAction: [
      { label: 'Découvrir mon approche', href: '#technologies', variant: 'primary', iconKey: 'arrow-right' },
      { label: 'Me contacter', href: '#contact', variant: 'secondary', iconKey: 'message-circle' },
    ],
    highlights: [
      { label: 'Code propre', iconKey: 'code' },
      { label: 'Architecture scalable', iconKey: 'layers' },
      { label: 'Performance', iconKey: 'zap' },
      { label: 'Sécurité', iconKey: 'shield' },
    ],
  },

  about: {
    eyebrow: 'À propos',
    title: 'À propos de ce site',
    // Contenu volontairement minimal tant que l'authentification (cf. CLAUDE.md,
    // objectif n°9) n'est pas en place.
    message: "Site créé par moi et l'IA.",
  },

  technologies: {
    featured: [
      { name: 'Symfony', description: 'Framework PHP', iconKey: 'symfony' },
      { name: 'Docker', description: 'Conteneurisation', iconKey: 'docker' },
      { name: 'PostgreSQL', description: 'Base de données', iconKey: 'postgresql' },
      { name: 'Symfony Messenger', description: 'Communication asynchrone', iconKey: 'mail' },
      { name: 'Vue.js', description: 'Framework JS', iconKey: 'vuejs' },
      { name: 'TypeScript', description: 'Typage statique', iconKey: 'typescript' },
    ],
    additional: [
      { name: 'API Platform' },
      { name: 'Bootstrap' },
      { name: 'Git & GitHub' },
      { name: 'Nginx' },
      { name: 'Linux' },
      { name: 'CI/CD (GitHub Actions)' },
    ],
  },

  quality: {
    principles: [
      {
        title: 'DDD',
        description:
          'Modélisation du domaine métier pour créer des applications alignées sur les besoins réels et faciles à faire évoluer.',
        iconKey: 'boxes',
      },
      {
        title: 'SOLID',
        description: 'Des bases solides pour un code flexible, maintenable et extensible dans le temps.',
        iconKey: 'columns-3',
      },
      {
        title: 'Design Patterns',
        description:
          'Utilisation de patrons de conception adaptés pour résoudre des problèmes récurrents avec élégance et efficacité.',
        iconKey: 'puzzle',
      },
    ],
    traits: [
      { label: 'Architecture propre' },
      { label: 'Maintenabilité' },
      { label: 'Évolutivité' },
      { label: 'Qualité du code' },
      { label: 'Tests automatisés' },
      { label: 'Revues de code' },
      { label: 'Documentation claire' },
    ],
  },

  stats: [
    { value: '+50K', label: 'Lignes de code', iconKey: 'code' },
    { value: '10+', label: 'Technologies maîtrisées', iconKey: 'box' },
    { value: '100%', label: 'Engagement qualité', iconKey: 'users' },
    { value: '∞', label: 'Passion', iconKey: 'infinity' },
  ],
}

export default fr
