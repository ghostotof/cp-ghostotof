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

  experience: {
    eyebrow: 'Experience',
    description:
      "A ranking of the main technologies I've worked with, based on the cumulative time spent on each throughout my career, from most to least experienced. Durations are estimated from my professional history (years rounded to the nearest half-year).",
    technologies: [
      { name: 'PHP', years: 9.5, duration: '~9.5 years', iconKey: 'php' },
      { name: 'Symfony', years: 9.5, duration: '~9.5 years', iconKey: 'symfony' },
      { name: 'Docker', years: 7.5, duration: '~7.5 years', iconKey: 'docker' },
      { name: 'HTML', years: 5, duration: '~5 years', iconKey: 'html5' },
      { name: 'CSS', years: 5, duration: '~5 years', iconKey: 'css3' },
      { name: 'JavaScript', years: 5, duration: '~5 years', iconKey: 'javascript' },
      { name: 'MySQL', years: 5, duration: '~5 years', iconKey: 'mysql' },
      { name: 'Doctrine', years: 4.5, duration: '~4.5 years' },
      { name: 'API Platform', years: 4.5, duration: '~4.5 years' },
      { name: 'Symfony Messenger', years: 4.5, duration: '~4.5 years', iconKey: 'mail' },
      { name: 'PostgreSQL', years: 4.5, duration: '~4.5 years', iconKey: 'postgresql' },
      { name: 'PHPStan', years: 4.5, duration: '~4.5 years' },
      { name: 'AWS', years: 4.5, duration: '~4.5 years', iconKey: 'aws' },
      { name: 'SQL Server', years: 2, duration: '~2 years', iconKey: 'sqlserver' },
      { name: 'Cobol', years: 1, duration: '~1 year' },
      { name: 'DB2', years: 1, duration: '~1 year' },
      { name: 'MVS', years: 1, duration: '~1 year' },
      { name: 'CICS', years: 1, duration: '~1 year' },
      { name: 'Python', years: 0.5, duration: '~6 months', iconKey: 'python' },
    ],
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
      { name: 'CI/CD' },
      { name: 'Claude' },
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
