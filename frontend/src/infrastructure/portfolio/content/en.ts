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
