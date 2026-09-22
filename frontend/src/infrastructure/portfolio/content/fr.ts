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
    lastUpdated: 'Dernière mise à jour : 21 septembre 2026',
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
          "Les données du formulaire de contact sont transmises à la boîte email de l'éditeur, via le prestataire technique d'envoi d'emails utilisé en production (Scaleway, France), qui achemine aussi les invitations de compte, puis via un service de redirection d'emails (Cloudflare). L'adresse email d'un compte invité n'est visible que de l'éditeur. Lorsque vous choisissez un mot de passe, un fragment anonyme de son empreinte (5 caractères) est comparé à une base de mots de passe compromis (Have I Been Pwned) ; ni le mot de passe ni votre identité ne sont transmis. Aucune donnée n'est vendue, louée ou transmise à des tiers à des fins commerciales.",
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
          "L'hébergement, la base de données et l'envoi des emails sont situés en France. Seule exception : les messages du formulaire de contact sont relayés vers la boîte de l'éditeur par Cloudflare, Inc. (États-Unis), transfert encadré par le Data Privacy Framework UE–États-Unis et, à défaut, par les clauses contractuelles types de la Commission européenne.",
        ],
      },
    ],
  },
}

export default fr
