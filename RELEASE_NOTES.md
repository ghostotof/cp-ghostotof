# v0.17.0 — Bandeau d'information sur les cookies

Version de contenu frontend uniquement : aucune migration de schéma, aucun changement d'image
backend ni de manifeste Kubernetes. Déploiement sans interruption.

## Bandeau d'information sur les cookies

Un bandeau apparaît en bas de page à la première visite, en français et en anglais, et se ferme
d'un seul bouton « Compris ».

- **C'est une information, pas un recueil de consentement.** Le site ne dépose aucun cookie lors
  d'une simple visite ; seuls des cookies techniques strictement nécessaires (`BEARER`,
  `XSRF-TOKEN`) sont posés à la connexion ou à l'accès instantané, et les préférences restent en
  `localStorage`. Tous ces traceurs sont exemptés de consentement au sens des lignes directrices de
  la CNIL : proposer un « refuser » qui ne pourrait pas être honoré serait un faux choix, il n'y a
  donc qu'un bouton. Le jour où un traceur non exempté entrerait dans le site, c'est une vraie
  gestion du consentement qu'il faudrait, pas une extension de ce bandeau.
- **Non bloquant par construction** : région étiquetée pour les lecteurs d'écran, ni modale, ni
  piège de focus, ni overlay ; collé en bas par `position: sticky` en fin de mise en page, il ne
  recouvre jamais le pied de page ; fond opaque pour un contraste indépendant du contenu qui défile.
- La fermeture est mémorisée dans le navigateur (`localStorage`, clé `cookieNoticeDismissed`),
  jamais transmise au serveur, et tolère un stockage indisponible (navigation privée, données de
  site bloquées) : le bandeau se referme pour la visite et reparaîtra à la suivante.
- Le registre des traitements (§4.2) et la politique de confidentialité (FR/EN) décrivent ce
  stockage.
