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
    eyebrow: 'About',
    title: 'About this site',
    // Content is intentionally minimal until authentication is in place
    // (see CLAUDE.md, goal #9).
    message: 'Site built by me and AI.',
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
