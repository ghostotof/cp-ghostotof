import type { PortfolioLocaleContent } from './PortfolioLocaleContent'

const en: PortfolioLocaleContent = {
  hero: {
    eyebrow: 'Senior Web Developer',
    titleLead: 'I build applications that are',
    titleAccent: 'robust, performant, and scalable.',
    description:
      'A developer passionate about building modern, maintainable web solutions, with a focus on quality and user experience.',
    callsToAction: [
      { label: 'Discover my approach', href: '#technologies', variant: 'primary', iconKey: 'arrow-right' },
      { label: 'Get in touch', href: '#contact', variant: 'secondary', iconKey: 'message-circle' },
    ],
    highlights: [
      { label: 'Clean code', iconKey: 'code' },
      { label: 'Scalable architecture', iconKey: 'layers' },
      { label: 'Performance', iconKey: 'zap' },
      { label: 'Security', iconKey: 'shield' },
    ],
  },

  about: {
    site: {
      eyebrow: 'About this site',
      cards: [
        {
          title: 'Architecture',
          description:
            'Decoupled into two independent parts: a Symfony API on the backend and a Vue.js/TypeScript interface on the frontend, designed around DDD and clean architecture principles.',
          iconKey: 'layers',
        },
        {
          title: 'Tech stack',
          description:
            'Symfony, Doctrine/PostgreSQL, asynchronous messaging, Vue 3 and TypeScript, all orchestrated with Docker Compose for a reproducible environment.',
          iconKey: 'server',
        },
        {
          title: 'Privacy',
          description:
            'No personally identifiable information is visible without authentication: a generic guest account lets visitors explore the site with full confidentiality.',
          iconKey: 'shield',
        },
        {
          title: 'Design',
          description:
            'Designed and built by me, with the help of AI tools to speed up some steps while staying in control of the technical decisions.',
          iconKey: 'sparkles',
        },
      ],
    },
    me: {
      eyebrow: 'About me',
      technicalSubtitle: 'Technically',
      technicalCards: [
        {
          title: 'Senior PHP/Symfony developer',
          description:
            'Several years of experience in web development, with a strong interest in clean architectures (DDD, SOLID, Clean Architecture) and code that stays maintainable over time.',
          iconKey: 'code',
        },
        {
          title: 'Full-stack approach',
          description:
            'Comfortable across the whole chain: Symfony/Doctrine backend, Vue.js/TypeScript frontend, Docker containerization.',
          iconKey: 'boxes',
        },
        {
          title: 'Quality & security mindset',
          description:
            'Automated tests, static analysis, code reviews: quality, security, and maintainability are a priority, not an afterthought.',
          iconKey: 'shield',
        },
      ],
      personalSubtitle: 'Personally',
      personalCards: [
        {
          title: 'Curious and self-taught',
          description:
            'Always keeping an eye on new practices and technologies, and genuinely enjoying exploring them outside of work.',
          iconKey: 'lightbulb',
        },
        {
          title: 'Thorough and a good communicator',
          description:
            'I like understanding the why before the how, and documenting technical decisions so they stay understandable over time.',
          iconKey: 'target',
        },
        {
          title: 'Collaborative',
          description: 'I enjoy technical discussions, constructive challenge, and sharing knowledge within a team.',
          iconKey: 'users',
        },
      ],
    },
  },

  technologies: {
    featured: [
      { name: 'Symfony', description: 'PHP Framework', iconKey: 'symfony' },
      { name: 'Docker', description: 'Containerization', iconKey: 'docker' },
      { name: 'PostgreSQL', description: 'Database', iconKey: 'postgresql' },
      { name: 'Symfony Messenger', description: 'Async messaging', iconKey: 'mail' },
      { name: 'Vue.js', description: 'JS Framework', iconKey: 'vuejs' },
      { name: 'TypeScript', description: 'Static typing', iconKey: 'typescript' },
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
        description: 'Domain modeling to build applications aligned with real needs and easy to evolve.',
        iconKey: 'boxes',
      },
      {
        title: 'SOLID',
        description: 'A solid foundation for flexible, maintainable, and extensible code over time.',
        iconKey: 'columns-3',
      },
      {
        title: 'Design Patterns',
        description: 'Using proven design patterns to solve recurring problems elegantly and efficiently.',
        iconKey: 'puzzle',
      },
    ],
    traits: [
      { label: 'Clean architecture' },
      { label: 'Maintainability' },
      { label: 'Scalability' },
      { label: 'Code quality' },
      { label: 'Automated tests' },
      { label: 'Code reviews' },
      { label: 'Clear documentation' },
    ],
  },

  stats: [
    { value: '+50K', label: 'Lines of code', iconKey: 'code' },
    { value: '10+', label: 'Technologies mastered', iconKey: 'box' },
    { value: '100%', label: 'Quality commitment', iconKey: 'users' },
    { value: '∞', label: 'Passion', iconKey: 'infinity' },
  ],
}

export default en
