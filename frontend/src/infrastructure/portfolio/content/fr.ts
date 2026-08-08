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
    site: {
      eyebrow: 'À propos de ce site',
      cards: [
        {
          title: 'Architecture',
          description:
            "Application découplée en deux parties indépendantes : une API Symfony côté backend et une interface Vue.js/TypeScript côté frontend, conçues selon les principes du DDD et de l'architecture propre.",
          iconKey: 'layers',
        },
        {
          title: 'Stack technique',
          description:
            'Symfony, Doctrine/PostgreSQL, messagerie asynchrone, Vue 3 et TypeScript, le tout orchestré avec Docker Compose pour un environnement reproductible.',
          iconKey: 'server',
        },
        {
          title: 'Confidentialité',
          description:
            "Aucune information personnelle identifiable n'est visible sans authentification : un compte invité générique permet de découvrir le site en toute confidentialité.",
          iconKey: 'shield',
        },
        {
          title: 'Conception',
          description:
            "Site conçu et développé par moi-même, avec l'aide d'outils d'intelligence artificielle pour accélérer certaines étapes tout en gardant la main sur les choix techniques.",
          iconKey: 'sparkles',
        },
      ],
    },
    me: {
      eyebrow: 'À propos de moi',
      technicalSubtitle: 'Techniquement',
      technicalCards: [
        {
          title: 'Développeur PHP/Symfony senior',
          description:
            "Plusieurs années d'expérience en développement web, avec une appétence pour les architectures propres (DDD, SOLID, Clean Architecture) et un code maintenable dans la durée.",
          iconKey: 'code',
        },
        {
          title: 'Approche full-stack',
          description:
            "À l'aise sur l'ensemble de la chaîne : backend Symfony/Doctrine, frontend Vue.js/TypeScript, conteneurisation Docker.",
          iconKey: 'boxes',
        },
        {
          title: 'Exigence qualité & sécurité',
          description:
            'Tests automatisés, analyse statique, revues de code : la qualité, la sécurité et la maintenabilité sont une priorité, pas une option.',
          iconKey: 'shield',
        },
      ],
      personalSubtitle: 'Humainement',
      personalCards: [
        {
          title: 'Curieux et autodidacte',
          description:
            'Toujours en veille technique, avec un vrai plaisir à explorer de nouvelles pratiques et technologies en dehors du cadre professionnel.',
          iconKey: 'lightbulb',
        },
        {
          title: 'Rigoureux et pédagogue',
          description:
            "J'aime comprendre le pourquoi avant le comment, et documenter mes choix techniques pour qu'ils restent compréhensibles dans le temps.",
          iconKey: 'target',
        },
        {
          title: 'Collaboratif',
          description:
            "J'apprécie les échanges techniques, la remise en question constructive et le partage de connaissances au sein d'une équipe.",
          iconKey: 'users',
        },
      ],
    },
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
      { name: 'CI/CD' },
      { name: 'Claude' },
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
