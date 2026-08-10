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
      { label: 'Me contacter', href: '/fr/contact', variant: 'secondary', iconKey: 'message-circle' },
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
      hobbiesSubtitle: 'En dehors du travail',
      hobbiesCards: [
        {
          title: 'Musique & guitare',
          description:
            "Je joue de la guitare et j'aime explorer différents styles musicaux ; je m'essaye aussi, doucement, à la MAO.",
          iconKey: 'guitar',
        },
        {
          title: 'Moto',
          description: "Amateur de moto, j'apprécie autant la mécanique que les sorties sur route.",
          iconKey: 'motorbike',
        },
        {
          title: 'Littérature',
          description: 'Grand lecteur, toujours un roman en cours à côté de mes lectures techniques.',
          iconKey: 'book-open',
        },
        {
          title: 'Cinéma & séries',
          description: "Cinéphile et amateur de séries, entre grands classiques et découvertes récentes.",
          iconKey: 'clapperboard',
        },
        {
          title: 'Plongée sous-marine',
          description: "La plongée sous-marine est une vraie passion, entre exploration et calme sous l'eau.",
          iconKey: 'waves',
        },
      ],
    },
  },

  experience: {
    eyebrow: 'Expériences',
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

  legalNotice: {
    eyebrow: 'Informations légales',
    title: 'Mentions légales',
    lastUpdated: 'Dernière mise à jour : 10 août 2026',
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
          '[HÉBERGEUR À COMPLÉTER] — raison sociale, adresse et contact de l’hébergeur seront précisés ici dès que l’environnement de production sera défini.',
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
    lastUpdated: 'Dernière mise à jour : 10 août 2026',
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
          "Authentification : nom d'utilisateur et mot de passe, pour l'unique compte de démonstration du site (aucune inscription publique n'est proposée).",
          "Adresse IP : utilisée de façon transitoire pour limiter les abus sur le formulaire de contact.",
          'Préférence de langue : mémorisée localement dans votre navigateur (localStorage), jamais transmise au serveur.',
        ],
      },
      {
        heading: 'Ce que nous ne faisons pas',
        paragraphs: [
          "Aucun message envoyé via le formulaire de contact n'est enregistré en base de données : il est transmis par email, sans conservation applicative au-delà de cet envoi.",
          "Le site n'utilise aucun cookie de mesure d'audience, de publicité ou de traceur tiers, et ne réalise aucun profilage ni décision automatisée.",
        ],
      },
      {
        heading: 'Finalités et bases légales',
        paragraphs: ['Vos données sont traitées pour les finalités suivantes :'],
        list: [
          'Répondre à vos demandes de contact — mesures précontractuelles ou intérêt légitime.',
          'Sécuriser le site et prévenir les abus (limitation de débit, journaux techniques) — intérêt légitime.',
          'Vous permettre de vous authentifier — exécution du service demandé.',
        ],
      },
      {
        heading: 'Cookies',
        paragraphs: [
          'Ce site pose uniquement des cookies strictement nécessaires à son fonctionnement, exemptés de consentement par les recommandations de la CNIL :',
        ],
        list: [
          'BEARER — jeton d’authentification (JWT), httpOnly, non lisible en JavaScript, expire à la déconnexion ou à l’expiration du jeton.',
          'XSRF-TOKEN — jeton de protection contre les attaques CSRF, lisible en JavaScript, posé uniquement après connexion, expire à la déconnexion.',
        ],
      },
      {
        heading: 'Durée de conservation',
        paragraphs: [
          "Les données du formulaire de contact ne sont pas stockées au-delà de l'envoi de l'email de notification. Les données techniques (adresse IP pour la limitation de débit, journaux serveur) sont conservées sur des durées courtes, détaillées dans le registre des traitements tenu par l'éditeur.",
        ],
      },
      {
        heading: 'Destinataires',
        paragraphs: [
          "Les données du formulaire de contact sont transmises à la boîte email de l'éditeur, via le prestataire technique d'envoi d'emails utilisé en production. Aucune donnée n'est vendue, louée ou transmise à des tiers à des fins commerciales.",
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
        paragraphs: ["Aucun transfert de données hors de l'Union européenne n'est effectué à ce jour."],
      },
    ],
  },
}

export default fr
