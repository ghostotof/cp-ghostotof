import type { PortfolioLocaleContent } from './PortfolioLocaleContent'

const en: PortfolioLocaleContent = {
  hero: {
    eyebrow: 'Senior Software Engineer (PHP/Symfony)',
    titleLead: 'This site is',
    titleAccent: 'its own demonstration.',
    description:
      'Not a brochure: a Symfony 8 API decoupled from a Vue 3/TypeScript frontend, split into DDD bounded contexts, analysed by PHPStan at max level and deployed to Kubernetes through GitHub Actions. The code is public — what you are reading is exactly what runs.',
    callsToAction: [
      {
        label: 'Read the code on GitHub',
        href: 'https://github.com/ghostotof/cp-ghostotof',
        variant: 'primary',
        iconKey: 'github',
        isExternal: true,
      },
      { label: 'How it is built', href: '/en/about', variant: 'secondary', iconKey: 'arrow-right' },
    ],
    highlights: [
      { label: 'DDD & layered architecture', iconKey: 'layers' },
      { label: 'PHPStan max level, no baseline', iconKey: 'shield' },
      { label: 'Tested — PHPUnit & Vitest', iconKey: 'check' },
      { label: 'Kubernetes & CI/CD', iconKey: 'boxes' },
    ],
  },

  experience: {
    eyebrow: 'Technical background',
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
      { name: 'Git' },
      { name: 'Nginx' },
      { name: 'Linux' },
      { name: 'CI/CD' },
      { name: 'Claude' },
    ],
  },

  legalNotice: {
    eyebrow: 'Legal information',
    title: 'Legal notice',
    lastUpdated: 'Last updated: September 2, 2026',
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
          'This site is hosted by Scaleway SAS, a company registered with the Paris Trade and Companies Register under number 433 115 904, with its registered office at 8 rue de la Ville l’Évêque, 75008 Paris, France — phone: +33 (0)1 84 13 00 00.',
          'Data and the application are hosted on Scaleway infrastructure located in France (fr-par region).',
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
    lastUpdated: 'Last updated: October 20, 2026',
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
          'Invited account: if the publisher grants you named access, your email address (to which the invitation is sent), a username derived from that address, and the password you choose, stored as a hash. No public sign-up is offered, no shared account.',
          'Instant access: no account is created and no data about you is recorded; access relies on an anonymous token valid for 15 minutes.',
          "IP address: used to rate-limit abuse (contact form, login, instant access, password setup) and recorded in the server's technical and security logs.",
          "MCP server (the site's access for an AI assistant): for each call, the technical log keeps the method, the tool or prompt called, the outcome and its error code, the duration, the name and version your software declares, and your truncated IP address: the last number of an IPv4 address and everything after the first three groups of an IPv6 address are erased. It then designates a network — often a subscriber's, a household or a company — rather than a device. The text your assistant sends to the server — a job description, for instance — is never recorded.",
          'Browser local storage (localStorage): your language preference and, where applicable, the expiry time of your instant access. These values are never sent to the server.',
        ],
      },
      {
        heading: "What we don't do",
        paragraphs: [
          'Contact form messages are not stored in a database beyond being forwarded by email, except when delivery fails (see "Retention period").',
          'The site uses no audience-measurement, advertising, or third-party tracking cookies, and performs no profiling or automated decision-making.',
          'The information banner shown on a first visit collects no consent, since no tracker requires one: the fact that you closed it is simply remembered in your browser (localStorage) and never sent to the server.',
        ],
      },
      {
        heading: 'Purposes and legal bases',
        paragraphs: ['Your data is processed for the following purposes:'],
        list: [
          'Responding to your contact requests — pre-contractual measures or legitimate interest.',
          'Securing the site and preventing abuse (rate limiting, technical logs) — legitimate interest.',
          'Measuring use of the MCP server and spotting abusive use — legitimate interest.',
          "Inviting you to hold named access — the publisher's legitimate interest in granting individual access rather than a shared credential.",
          'Letting you authenticate or use instant access — performance of the requested service.',
        ],
      },
      {
        heading: 'Cookies',
        paragraphs: [
          'This site sets no cookie on a plain visit. Two strictly necessary cookies are set only when you log in or request instant access; they are exempt from consent under CNIL (French data protection authority) guidance, which is why no consent banner is shown:',
        ],
        list: [
          'BEARER — authentication token (JWT), httpOnly, not readable from JavaScript, expires on logout or token expiry (1 hour for an account, 15 minutes for instant access).',
          'XSRF-TOKEN — CSRF protection token, readable from JavaScript, set together with the previous one, expires with it.',
        ],
      },
      {
        heading: 'Retention period',
        paragraphs: [
          "Contact form messages are not kept beyond sending the notification email. If delivery fails (mail server temporarily unavailable), the message is stored so it can be retried, then automatically deleted after 30 days at the latest. Technical data (IP address for rate limiting, server logs) is kept for short periods, detailed in the processing register maintained by the publisher.",
          'An invited account and its email address are kept for as long as access is granted to you; they are deleted when no longer needed or simply at your request. The invitation link is valid for 48 hours and can be used only once. An invitation that is never activated is deleted automatically 30 days after it was sent (or last re-sent).',
        ],
      },
      {
        heading: 'Recipients',
        paragraphs: [
          "Contact form data is sent to the publisher's mailbox, via the email delivery provider used in production (Scaleway, France), which also delivers account invitations; that mailbox is hosted in France (OVHcloud). An invited account's email address is visible to the publisher only. When you choose a password, an anonymous fragment of its hash (5 characters) is checked against a database of compromised passwords (Have I Been Pwned); neither the password nor your identity is transmitted. No data is sold, rented, or shared with third parties for commercial purposes.",
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
        paragraphs: [
          "No data is transferred outside the European Union: hosting, the database, email delivery and the publisher's mailbox are all located in France.",
        ],
      },
    ],
  },

  connectAssistant: {
    eyebrow: 'MCP server',
    title: 'Connect your assistant',
    introduction: [
      'Your AI assistant — Claude, ChatGPT, the one in your editor — can query this portfolio directly, through the MCP protocol (Model Context Protocol). Add the site’s server to your MCP client, then ask your questions: your assistant reads the content at its source instead of guessing it.',
      'No account, no token: the server connects as is. The site calls no model; your assistant, at your AI provider, reads the answers.',
    ],
    serverUrl: {
      heading: 'Server URL',
      description: 'The address to give your MCP client (Streamable HTTP transport).',
      copyLabel: 'the server URL',
    },
    served: {
      heading: 'What the server serves',
      description:
        'Exactly what the site’s public pages already serve, read-only. Tools that take a language accept `locale`: `fr` (the default) or `en`.',
      tiers: [
        {
          id: 'anonymous',
          heading: 'Anonymous tier',
          description: 'The content the site shows to every visitor, with no access and no account.',
          tools: [
            { tool: 'get_about', description: 'About: the site, how it is built and why.' },
            { tool: 'get_quality', description: 'The quality principles and requirements applied to the code.' },
            { tool: 'get_technical_background', description: 'The technical background: technologies practised and for how long.' },
            { tool: 'list_contributions', description: 'Technical contributions, open source included.' },
            { tool: 'list_incidents', description: 'Production incidents and the invariants that came out of them.' },
            { tool: 'get_tech_watch', description: 'The tech watch: installed versions and support deadlines.' },
            { tool: 'get_contact_info', description: 'The address of the site’s contact page, never an email address.' },
          ],
        },
        {
          id: 'base',
          heading: 'Base tier',
          description:
            'The content the site opens in one click with instant access, without an account. On the MCP server it is served without a token; it names no one.',
          tools: [
            { tool: 'get_anonymous_cv', description: 'The CV without identity: areas of expertise, seniority, achievements.' },
            { tool: 'list_case_studies', description: 'Case studies: real problems and technical trade-offs.' },
          ],
        },
      ],
      prompt: {
        heading: 'A prompt',
        name: 'present_for_role',
        description:
          'Present the site’s author for a role you describe: your assistant goes through the tools above, cites the one each statement comes from and says what it does not know.',
        arguments:
          'Arguments: `role`, the role’s description (free text, 500 characters at most), and `locale`, `fr` (the default) or `en`.',
      },
    },
    neverServed: {
      heading: 'What the server never serves',
      items: [
        'Nothing nominative without an MCP connection: no name, no employers, no career history, no nominative CV. The `get_nominative_corpus` tool is listed, but answers that an MCP connection is required; that connection, reserved for the trusted tier, is not open yet.',
        'No write: the server only reads. It does not send the contact form on your behalf; it gives you its address.',
        'No model call: the site runs no AI to answer you.',
        'Never the CV’s PDF.',
      ],
    },
    privacy: {
      text: 'Each call is written to the site’s technical log with a truncated IP address and the name your software declares, never with the text your assistant sends. The details are in the privacy policy.',
      linkLabel: 'Read the privacy policy',
    },
    clients: {
      heading: 'Set up your client',
      description:
        'Menu labels change from one version to the next: if yours differ, look for “MCP” or “connector” in the settings.',
      guides: [
        {
          id: 'claude-code',
          name: 'Claude Code',
          steps: [
            'In a terminal, run the command below.',
            'Add `--scope user` after `--transport http` to connect the server in all your projects, not only in the current folder.',
            'In a session, `/mcp` shows the server’s status and its tools.',
          ],
          configuration: { kind: 'claude-code-command', copyLabel: 'the Claude Code command' },
        },
        {
          id: 'claude-apps',
          name: 'Claude Desktop & claude.ai',
          steps: [
            'Open Settings → Connectors, then “Add custom connector”.',
            'Give it a name and paste the server URL; leave the authentication settings empty.',
            'Turn the connector on in a conversation, from the tools menu.',
          ],
          configuration: { kind: 'server-url', copyLabel: 'the server URL for Claude Desktop and claude.ai' },
          note: 'Custom connectors depend on your Claude plan.',
        },
        {
          id: 'chatgpt',
          name: 'ChatGPT',
          steps: [
            'Open Settings → Apps & Connectors → Advanced settings, and turn developer mode on.',
            'Create a connector, paste the server URL, choose “No authentication” and confirm you trust the application if the interface asks.',
            'Then add it to a conversation, from the tools menu.',
          ],
          configuration: { kind: 'server-url', copyLabel: 'the server URL for ChatGPT' },
          note: 'Developer mode depends on your ChatGPT plan.',
        },
        {
          id: 'cursor',
          name: 'Cursor',
          steps: [
            'Open `~/.cursor/mcp.json` (all your projects) or `.cursor/mcp.json` at the root of a project.',
            'Paste the snippet below; if the file already holds `mcpServers`, add only the `cp-ghostotof` entry to it.',
            'The server shows up in Cursor’s MCP settings.',
          ],
          configuration: { kind: 'mcp-servers-json', copyLabel: 'the Cursor configuration' },
        },
        {
          id: 'phpstorm',
          name: 'PhpStorm',
          steps: [
            'Open Settings → Tools → AI Assistant → Model Context Protocol (MCP), then “Add”.',
            'Choose the JSON input and paste the snippet below.',
            'Confirm: the server is available in the AI Assistant chat.',
          ],
          configuration: { kind: 'mcp-servers-json', copyLabel: 'the PhpStorm configuration' },
          note: 'A remote server by URL needs the AI Assistant of PhpStorm 2025.2 or later.',
        },
      ],
    },
  },
}

export default en
