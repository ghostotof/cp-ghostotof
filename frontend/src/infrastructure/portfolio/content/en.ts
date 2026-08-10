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
      { label: 'Get in touch', href: '/en/contact', variant: 'secondary', iconKey: 'message-circle' },
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
      hobbiesSubtitle: 'Outside of work',
      hobbiesCards: [
        {
          title: 'Music & guitar',
          description:
            "I play the guitar and enjoy exploring different musical styles; I'm also slowly dabbling in music production.",
          iconKey: 'guitar',
        },
        {
          title: 'Motorcycling',
          description: 'A motorcycle enthusiast, I enjoy both the mechanics and the rides.',
          iconKey: 'motorbike',
        },
        {
          title: 'Literature',
          description: 'An avid reader, always with a novel on the go alongside technical reading.',
          iconKey: 'book-open',
        },
        {
          title: 'Movies & TV shows',
          description: 'A film and TV buff, enjoying both classics and recent discoveries.',
          iconKey: 'clapperboard',
        },
        {
          title: 'Scuba diving',
          description: 'Scuba diving is a real passion, between exploration and calm underwater.',
          iconKey: 'waves',
        },
      ],
    },
  },

  experience: {
    eyebrow: 'Experiences',
    description:
      "A ranking of the main technologies I've worked with, based on the cumulative time spent on each throughout my career and my studies, from most to least experienced. Durations are estimated from my professional history and coursework (years rounded to the nearest half-year).",
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

  legalNotice: {
    eyebrow: 'Legal information',
    title: 'Legal notice',
    lastUpdated: 'Last updated: August 10, 2026',
    sections: [
      {
        heading: 'Site publisher',
        paragraphs: [
          "This site is published by a private individual acting in a non-professional capacity, within the meaning of article 6-III of French law n° 2004-575 of 21 June 2004 (LCEN).",
          'In accordance with this provision, the publisher has chosen not to disclose their identity and address publicly; this information is kept available to the site host and, upon request, to the judicial authority.',
          'The publisher can be reached at contact@cp-ghostotof.com.',
        ],
      },
      {
        heading: 'Publication director',
        paragraphs: ['The publication director is the site publisher identified above.'],
      },
      {
        heading: 'Hosting',
        paragraphs: [
          '[HOSTING PROVIDER TO BE COMPLETED] — the host’s company name, address and contact details will be added here once the production environment is set up.',
        ],
      },
      {
        heading: 'Intellectual property',
        paragraphs: [
          'All content on this site (text, source code, structure, layout) is the property of the publisher unless stated otherwise. Reproduction without prior authorization is prohibited, except for elements explicitly released as open source.',
        ],
      },
      {
        heading: 'Nature of the site',
        paragraphs: [
          'This site is a personal demonstration project intended to showcase technical skills (portfolio). It does not constitute a commercial activity and does not sell goods or offer paid services online.',
        ],
      },
      {
        heading: 'Contact',
        paragraphs: ['For any question about the site or this legal notice: contact@cp-ghostotof.com.'],
      },
    ],
  },

  privacyPolicy: {
    eyebrow: 'Privacy',
    title: 'Privacy policy',
    lastUpdated: 'Last updated: August 10, 2026',
    sections: [
      {
        heading: 'Data controller',
        paragraphs: [
          'The controller for data collected on this site is its publisher (see the legal notice), reachable at contact@cp-ghostotof.com.',
        ],
      },
      {
        heading: 'Data collected',
        paragraphs: ['Depending on how you use the site, the following data may be collected:'],
        list: [
          'Contact form: name, email address and message you voluntarily enter.',
          "Authentication: username and password, for the site's single demo account (no public sign-up is offered).",
          'IP address: used transiently to rate-limit abuse of the contact form.',
          'Language preference: stored locally in your browser (localStorage), never sent to the server.',
        ],
      },
      {
        heading: "What we don't do",
        paragraphs: [
          'No message sent through the contact form is stored in a database: it is forwarded by email with no application-level retention beyond that.',
          'The site uses no audience-measurement, advertising, or third-party tracking cookies, and performs no profiling or automated decision-making.',
        ],
      },
      {
        heading: 'Purposes and legal bases',
        paragraphs: ['Your data is processed for the following purposes:'],
        list: [
          'Responding to your contact requests — pre-contractual measures or legitimate interest.',
          'Securing the site and preventing abuse (rate limiting, technical logs) — legitimate interest.',
          'Letting you authenticate — performance of the requested service.',
        ],
      },
      {
        heading: 'Cookies',
        paragraphs: [
          'This site only sets cookies strictly necessary for its operation, exempt from consent under CNIL (French data protection authority) guidance:',
        ],
        list: [
          'BEARER — authentication token (JWT), httpOnly, not readable from JavaScript, expires on logout or token expiry.',
          'XSRF-TOKEN — CSRF protection token, readable from JavaScript, only set after login, expires on logout.',
        ],
      },
      {
        heading: 'Retention period',
        paragraphs: [
          "Contact form data is not stored beyond sending the notification email. Technical data (IP address for rate limiting, server logs) is kept for short periods, detailed in the processing register maintained by the publisher.",
        ],
      },
      {
        heading: 'Recipients',
        paragraphs: [
          "Contact form data is sent to the publisher's mailbox, via the email delivery provider used in production. No data is sold, rented, or shared with third parties for commercial purposes.",
        ],
      },
      {
        heading: 'Your rights',
        paragraphs: [
          'Under the General Data Protection Regulation (GDPR), you have the right to access, rectify, erase, restrict, object to, and port your data. You can exercise these rights by writing to contact@cp-ghostotof.com; you will receive a response within one month.',
          'You also have the right to lodge a complaint with the French data protection authority (CNIL) — www.cnil.fr.',
        ],
      },
      {
        heading: 'Transfers outside the European Union',
        paragraphs: ['No data transfer outside the European Union currently takes place.'],
      },
    ],
  },
}

export default en
