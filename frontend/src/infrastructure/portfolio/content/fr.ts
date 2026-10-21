import type { PortfolioLocaleContent } from './PortfolioLocaleContent'

const fr: PortfolioLocaleContent = {
  hero: {
    eyebrow: 'Ingénieur logiciel senior PHP / Symfony',
    titleLead: 'Ce site est',
    titleAccent: 'sa propre démonstration.',
    description:
      "Pas une plaquette : une API Symfony 8 découplée d'un front Vue 3/TypeScript, découpée en contextes DDD, analysée en PHPStan niveau max et déployée sur Kubernetes via GitHub Actions. Le code est public — ce que vous lisez ici est exactement ce qui tourne.",
    callsToAction: [
      {
        label: 'Lire le code sur GitHub',
        href: 'https://github.com/ghostotof/cp-ghostotof',
        variant: 'primary',
        iconKey: 'github',
        isExternal: true,
      },
      { label: 'Comment il est construit', href: '/fr/about', variant: 'secondary', iconKey: 'arrow-right' },
    ],
    highlights: [
      { label: 'DDD & architecture en couches', iconKey: 'layers' },
      { label: 'PHPStan niveau max, sans baseline', iconKey: 'shield' },
      { label: 'Testé — PHPUnit & Vitest', iconKey: 'check' },
      { label: 'Kubernetes & CI/CD', iconKey: 'boxes' },
    ],
  },

  experience: {
    eyebrow: 'Parcours technique',
    description:
      "Classement de mes principales technologies selon le temps cumulé passé dessus au fil de mon parcours professionnel et de mes études, de la plus expérimentée à la plus récente. Durées estimées à partir de l'historique de mes missions et de mes cursus (années arrondies au semestre).",
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
      { name: 'Git' },
      { name: 'Nginx' },
      { name: 'Linux' },
      { name: 'CI/CD' },
      { name: 'Claude' },
    ],
  },

  legalNotice: {
    eyebrow: 'Informations légales',
    title: 'Mentions légales',
    lastUpdated: 'Dernière mise à jour : 2 septembre 2026',
    sections: [
      {
        heading: 'Éditeur du site',
        paragraphs: [
          "Ce site est édité par une personne physique agissant à titre non professionnel, au sens de l'article 6-III de la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l'économie numérique (LCEN).",
          "Conformément à cette disposition, l'éditeur a choisi de ne pas rendre publiques son identité et son adresse ; ces informations sont tenues à la disposition de l'hébergeur du site ainsi que, sur demande, de l'autorité judiciaire.",
          "L'éditeur est joignable à l'adresse contact@cp-ghostotof.com.",
        ],
      },
      {
        heading: 'Directeur de la publication',
        paragraphs: ['Le directeur de la publication est l’éditeur du site, identifié ci-dessus.'],
      },
      {
        heading: 'Hébergement',
        paragraphs: [
          'Ce site est hébergé par Scaleway SAS, société immatriculée au Registre du commerce et des sociétés de Paris sous le numéro 433 115 904, dont le siège social est situé 8 rue de la Ville l’Évêque, 75008 Paris, France — téléphone : +33 (0)1 84 13 00 00.',
          'Les données et l’application sont hébergées sur l’infrastructure Scaleway localisée en France (région fr-par).',
        ],
      },
      {
        heading: 'Propriété intellectuelle',
        paragraphs: [
          "L'ensemble des contenus présents sur ce site (textes, code source, structure, mise en page) est la propriété de l'éditeur, sauf mention contraire. Toute reproduction sans autorisation préalable est interdite, à l'exception des éléments explicitement placés en open source.",
        ],
      },
      {
        heading: 'Nature du site',
        paragraphs: [
          "Ce site est un projet de démonstration personnel destiné à présenter des compétences techniques (portfolio). Il ne constitue pas une activité commerciale et ne propose ni vente de biens ni prestation de services en ligne.",
        ],
      },
      {
        heading: 'Contact',
        paragraphs: ['Pour toute question relative au site ou à ces mentions légales : contact@cp-ghostotof.com.'],
      },
    ],
  },

  privacyPolicy: {
    eyebrow: 'Vie privée',
    title: 'Politique de confidentialité',
    lastUpdated: 'Dernière mise à jour : 21 octobre 2026',
    sections: [
      {
        heading: 'Responsable du traitement',
        paragraphs: [
          'Le responsable du traitement des données collectées sur ce site est son éditeur (voir les mentions légales), joignable à contact@cp-ghostotof.com.',
        ],
      },
      {
        heading: 'Données collectées',
        paragraphs: ['Selon la façon dont vous utilisez le site, les données suivantes peuvent être collectées :'],
        list: [
          'Formulaire de contact : nom, adresse email et message que vous saisissez volontairement.',
          "Compte sur invitation : si l'éditeur vous accorde un accès nominatif, votre adresse email (à laquelle l'invitation est envoyée), un nom d'utilisateur dérivé de cette adresse et le mot de passe que vous choisissez, conservé sous forme hachée. Aucune inscription publique n'est proposée, aucun compte partagé.",
          "Accès instantané : aucun compte n'est créé et aucune donnée vous concernant n'est enregistrée ; l'accès repose sur un jeton anonyme valable 15 minutes.",
          "Adresse IP : utilisée pour limiter les abus (formulaire de contact, connexion, accès instantané, définition du mot de passe) et consignée dans les journaux techniques et de sécurité du serveur.",
          "Serveur MCP (l'accès du site pour un assistant IA) : pour chaque appel, le journal technique garde la méthode, l'outil ou le prompt appelé, l'issue et son code d'erreur, la durée, le nom et la version que votre logiciel déclare, et votre adresse IP tronquée : le dernier nombre d'une adresse IPv4 et tout ce qui suit les trois premiers groupes d'une adresse IPv6 sont effacés. Elle désigne alors un réseau — souvent celui d'un abonné, un foyer ou une entreprise — et non plus un appareil. Le texte que votre assistant transmet au serveur — la description d'un poste, par exemple — n'est jamais conservé : il ne fait que transiter par la base de données, le temps de vous répondre.",
          "Stockage local du navigateur (localStorage) : votre préférence de langue et, le cas échéant, l'heure d'expiration de votre accès instantané. Ces valeurs ne sont jamais transmises au serveur.",
        ],
      },
      {
        heading: 'Ce que nous ne faisons pas',
        paragraphs: [
          "Les messages du formulaire de contact ne sont pas conservés en base de données au-delà de leur transmission par email, sauf en cas d'échec d'envoi (voir « Durée de conservation »).",
          "Le site n'utilise aucun cookie de mesure d'audience, de publicité ou de traceur tiers, et ne réalise aucun profilage ni décision automatisée.",
          "Le bandeau d'information affiché à la première visite ne recueille aucun consentement, puisqu'aucun traceur ne l'exige : le fait que vous l'ayez fermé est simplement mémorisé dans votre navigateur (localStorage), jamais transmis au serveur.",
        ],
      },
      {
        heading: 'Finalités et bases légales',
        paragraphs: ['Vos données sont traitées pour les finalités suivantes :'],
        list: [
          'Répondre à vos demandes de contact — mesures précontractuelles ou intérêt légitime.',
          'Sécuriser le site et prévenir les abus (limitation de débit, journaux techniques) — intérêt légitime.',
          "Mesurer l'usage du serveur MCP et repérer un usage abusif — intérêt légitime.",
          "Vous inviter à disposer d'un accès nominatif — intérêt légitime de l'éditeur à accorder un accès individuel plutôt qu'un identifiant partagé.",
          "Vous permettre de vous authentifier ou d'utiliser l'accès instantané — exécution du service demandé.",
        ],
      },
      {
        heading: 'Cookies',
        paragraphs: [
          "Ce site ne pose aucun cookie lors d'une simple visite. Deux cookies strictement nécessaires sont posés uniquement lorsque vous vous connectez ou demandez l'accès instantané ; ils sont exemptés de consentement par les recommandations de la CNIL, raison pour laquelle aucun bandeau de consentement ne vous est présenté :",
        ],
        list: [
          'BEARER — jeton d’authentification (JWT), httpOnly, non lisible en JavaScript, expire à la déconnexion ou à l’expiration du jeton (1 heure pour un compte, 15 minutes pour l’accès instantané).',
          'XSRF-TOKEN — jeton de protection contre les attaques CSRF, lisible en JavaScript, posé en même temps que le précédent, expire avec lui.',
        ],
      },
      {
        heading: 'Durée de conservation',
        paragraphs: [
          "Les messages du formulaire de contact ne sont pas conservés au-delà de l'envoi de l'email de notification. En cas d'échec d'envoi (serveur de messagerie temporairement indisponible), le message est stocké pour permettre une nouvelle tentative, puis supprimé automatiquement au plus tard au bout de 30 jours. Les données techniques (adresse IP pour la limitation de débit, journaux serveur) sont conservées sur des durées courtes, détaillées dans le registre des traitements tenu par l'éditeur.",
          "Un compte sur invitation et l'adresse email associée sont conservés tant que l'accès vous est accordé ; ils sont supprimés à la fin du besoin ou sur simple demande de votre part. Le lien d'invitation est valable 48 heures et ne peut servir qu'une fois. Une invitation jamais activée est supprimée automatiquement 30 jours après son envoi (ou son dernier renvoi).",
        ],
      },
      {
        heading: 'Destinataires',
        paragraphs: [
          "Les données du formulaire de contact sont transmises à la boîte email de l'éditeur, via le prestataire technique d'envoi d'emails utilisé en production (Scaleway, France), qui achemine aussi les invitations de compte ; cette boîte est hébergée en France (OVHcloud). L'adresse email d'un compte sur invitation n'est visible que de l'éditeur. Lorsque vous choisissez un mot de passe, un fragment anonyme de son empreinte (5 caractères) est comparé à une base de mots de passe compromis (Have I Been Pwned) ; ni le mot de passe ni votre identité ne sont transmis. Aucune donnée n'est vendue, louée ou transmise à des tiers à des fins commerciales.",
        ],
      },
      {
        heading: 'Vos droits',
        paragraphs: [
          "Conformément au Règlement Général sur la Protection des Données (RGPD), vous disposez d'un droit d'accès, de rectification, d'effacement, de limitation, d'opposition et de portabilité sur vos données. Vous pouvez exercer ces droits en écrivant à contact@cp-ghostotof.com ; une réponse vous sera apportée dans un délai d'un mois.",
          "Vous disposez également du droit d'introduire une réclamation auprès de la Commission Nationale de l'Informatique et des Libertés (CNIL) — www.cnil.fr.",
        ],
      },
      {
        heading: 'Transferts hors Union européenne',
        paragraphs: [
          "Aucun transfert de données hors de l'Union européenne n'est effectué : l'hébergement, la base de données, l'envoi des emails et la boîte de réception de l'éditeur sont situés en France.",
        ],
      },
    ],
  },

  connectAssistant: {
    eyebrow: 'Serveur MCP',
    title: 'Brancher votre assistant',
    introduction: [
      "Votre assistant IA — Claude, ChatGPT, celui de votre éditeur — peut interroger ce portfolio directement, par le protocole MCP (Model Context Protocol). Ajoutez le serveur du site à votre client MCP, puis posez vos questions : votre assistant lit les contenus à la source au lieu de les deviner.",
      "Ni compte ni jeton : le serveur se branche tel quel. Le site n'appelle aucun modèle ; c'est votre assistant, chez votre fournisseur d'IA, qui lit les réponses.",
    ],
    serverUrl: {
      heading: 'URL du serveur',
      description: "L'adresse à donner à votre client MCP (transport Streamable HTTP).",
      copyLabel: "l'URL du serveur",
    },
    // Recopie du catalogue du serveur MCP : outils par palier, prompt, ses
    // arguments et la borne de `role`. ConnectAssistantPageMirrorsCatalogueTest
    // (backend, #673) la compare au registre réel du serveur, dans chaque langue.
    served: {
      heading: 'Ce que le serveur sert',
      description:
        "Exactement ce que le site sert déjà sans compte, en lecture seule. Les outils qui prennent une langue acceptent `locale` : `fr` (par défaut) ou `en`.",
      tiers: [
        {
          id: 'anonymous',
          heading: 'Palier anonyme',
          description: 'Les contenus que le site affiche à tout visiteur, sans accès ni compte.',
          tools: [
            { tool: 'get_about', description: 'À propos : le site, comment il est construit et pourquoi.' },
            { tool: 'get_quality', description: 'Les principes et les exigences de qualité appliqués au code.' },
            { tool: 'get_technical_background', description: 'Le parcours technique : technologies pratiquées et ancienneté.' },
            { tool: 'list_contributions', description: 'Les contributions techniques, open source comprises.' },
            { tool: 'list_incidents', description: 'Les incidents de production et les invariants qui en sont sortis.' },
            { tool: 'get_tech_watch', description: "La veille technique : versions installées et échéances de support." },
            { tool: 'get_contact_info', description: "L'adresse de la page de contact du site, jamais une adresse email." },
          ],
        },
        {
          id: 'base',
          heading: 'Palier de base',
          description:
            "Les contenus que le site ouvre d'un clic avec l'accès instantané, sans compte. Sur le serveur MCP, ils sont servis sans jeton ; ils ne nomment personne.",
          tools: [
            { tool: 'get_anonymous_cv', description: 'Le CV sans identité : domaines de compétence, ancienneté, réalisations.' },
            { tool: 'list_case_studies', description: 'Les études de cas : problèmes réels et compromis techniques.' },
          ],
        },
      ],
      prompt: {
        heading: 'Un prompt',
        name: 'present_for_role',
        description:
          "Présenter l'auteur du site pour un poste que vous décrivez : votre assistant parcourt les outils ci-dessus, cite celui d'où vient chaque affirmation et dit ce qu'il ne sait pas.",
        arguments:
          'Arguments : `role`, la description du poste (texte libre, 500 caractères au plus), et `locale`, `fr` (par défaut) ou `en`.',
      },
    },
    // « Pas encore ouverte » : vrai en phase 1 seulement. La phase 2 de la spec
    // 0007 (« Page « Brancher votre assistant » complétée ») réécrit cet élément
    // en ouvrant la connexion MCP.
    neverServed: {
      heading: 'Ce que le serveur ne sert jamais',
      items: [
        "Rien de nominatif sans connexion MCP : ni nom, ni employeurs, ni chronologie professionnelle, ni CV nominatif. L'outil `get_nominative_corpus` est listé, mais répond qu'une connexion MCP est requise ; cette connexion, réservée au palier de confiance, n'est pas encore ouverte.",
        "Aucune écriture : le serveur ne fait que lire. Il n'envoie pas le formulaire de contact à votre place ; il vous en donne l'adresse.",
        "Aucun appel de modèle : le site ne fait tourner aucune IA pour vous répondre.",
        "Jamais le PDF du CV.",
      ],
    },
    privacy: {
      text: "Chaque appel est inscrit au journal technique du site avec une adresse IP tronquée et le nom que votre logiciel déclare, jamais avec le texte que votre assistant envoie. Le détail est dans la politique de confidentialité.",
      linkLabel: 'Lire la politique de confidentialité',
    },
    clients: {
      heading: 'Configurer votre client',
      description:
        "Les libellés des menus changent d'une version à l'autre : s'ils diffèrent chez vous, cherchez « MCP » ou « connecteur » dans les réglages.",
      guides: [
        {
          id: 'claude-code',
          name: 'Claude Code',
          steps: [
            'Dans un terminal, lancez la commande ci-dessous.',
            "Ajoutez `--scope user` après `--transport http` pour brancher le serveur dans tous vos projets, et pas seulement dans le dossier courant.",
            "Dans une session, `/mcp` affiche l'état du serveur et ses outils.",
          ],
          configuration: { kind: 'claude-code-command', copyLabel: 'la commande Claude Code' },
        },
        {
          id: 'claude-apps',
          name: 'Claude Desktop & claude.ai',
          steps: [
            'Ouvrez Paramètres → Connecteurs, puis « Ajouter un connecteur personnalisé ».',
            "Donnez-lui un nom et collez l'URL du serveur ; laissez vides les réglages d'authentification.",
            'Activez le connecteur dans une conversation, depuis le menu des outils.',
          ],
          configuration: { kind: 'server-url', copyLabel: "l'URL du serveur pour Claude Desktop et claude.ai" },
          note: "Les connecteurs personnalisés dépendent de votre offre Claude.",
        },
        {
          id: 'chatgpt',
          name: 'ChatGPT',
          steps: [
            'Ouvrez Paramètres → Applications et connecteurs → Paramètres avancés, et activez le mode développeur.',
            "Créez un connecteur, collez l'URL du serveur, choisissez « Aucune authentification » et confirmez faire confiance à l'application si l'interface le demande.",
            'Ajoutez-le ensuite à une conversation, depuis le menu des outils.',
          ],
          configuration: { kind: 'server-url', copyLabel: "l'URL du serveur pour ChatGPT" },
          note: 'Le mode développeur dépend de votre offre ChatGPT.',
        },
        {
          id: 'cursor',
          name: 'Cursor',
          steps: [
            'Ouvrez `~/.cursor/mcp.json` (tous vos projets) ou `.cursor/mcp.json` à la racine d’un projet.',
            "Collez le fragment ci-dessous ; si le fichier contient déjà `mcpServers`, ajoutez-y seulement l'entrée `cp-ghostotof`.",
            'Le serveur apparaît dans les réglages MCP de Cursor.',
          ],
          configuration: { kind: 'mcp-servers-json', copyLabel: 'la configuration Cursor' },
        },
        {
          id: 'phpstorm',
          name: 'PhpStorm',
          steps: [
            'Ouvrez Settings → Tools → AI Assistant → Model Context Protocol (MCP), puis « Add ».',
            'Choisissez la saisie en JSON et collez le fragment ci-dessous.',
            "Validez : le serveur est disponible dans le chat de l'AI Assistant.",
          ],
          configuration: { kind: 'mcp-servers-json', copyLabel: 'la configuration PhpStorm' },
          note: "Un serveur distant par URL demande l'AI Assistant de PhpStorm 2025.2 ou plus récent.",
        },
      ],
    },
  },
}

export default fr
