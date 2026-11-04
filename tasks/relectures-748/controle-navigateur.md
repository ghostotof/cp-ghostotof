# Tâche 11 de #748 — contrôle au navigateur (2026-11-04)

Pile de dev locale (Vite `localhost:5173`, API `localhost:8080`, deux origines), Chrome 156 piloté par Claude in Chrome,
axe-core 4.14.0 (dépendance de développement du projet, servie par Vite), règles WCAG 2.0, 2.1 et 2.2 A et AA, plus la règle
`label-content-name-mismatch` (2.5.3, expérimentale dans axe). Deux comptes de test jetables, supprimés à la fin du contrôle :
un `ROLE_SUPER` créé par `app:user:create` (sans adresse), un compte invité depuis le backoffice (adresse en `example.invalid`,
mot de passe posé par le backoffice ; aucun worker Messenger ne tourne en dev, l'invitation n'est jamais partie). Les gestes de
l'« autre compte » (une rétrogradation) sont faits par l'API en ligne de commande, cookies dans un pot `0600`.
Comptes supprimés après le contrôle (l'invité par l'API, le `ROLE_SUPER` en SQL ciblé sur son id et son nom, l'API refusant la
suppression de son propre compte) ; mots de passe générés, pot de cookies et script effacés. Le message d'invitation resté en
file sera écarté par le handler (compte absent).

## Défauts trouvés

| # | Constat | Sévérité | Suite |
|---|---|---|---|
| 1 | WCAG 2.5.3 (A) : les boutons « FR » / « EN » du sélecteur avaient pour nom « Français » / « English » (axe `label-content-name-mismatch`, serious), sur toutes les pages, depuis 70922f94 | moyen | corrigé par R243 (ca4a79e5) ; revérifié au navigateur : plus aucune violation |
| 2 | WCAG 1.4.10 (AA) : à 320 px, la page d'accueil déborde de 12 px (`HeroSection.vue:17`, `.row.g-5` : gouttière de 3rem, marge négative au-delà du padding du conteneur), FR et EN, présent sur `develop` | moyen | corrigé par R244 (961d870b, `g-4 g-lg-5`) ; remesuré : 305/305 en FR et EN, gouttière de 3rem gardée à 1280 px |
| 3 | Le menu d'actions de la dernière ligne du tableau des comptes s'ouvre dans le conteneur du tableau : ses dernières entrées sont hors de vue sans défilement | faible | écrit, antérieur |
| 4 | Le formulaire « Textes de la page À propos » n'affiche aucun retour après un enregistrement réussi ; « Session rétablie » reste affiché après lui | faible | écrit, antérieur |

## Mesures, conformes

- **axe**, aucune violation (après R243) : accueil FR, « À propos » EN, « Mes connexions MCP » FR et EN (état vide), assistant,
  « Brancher votre assistant » EN, études de cas, backoffice « À propos », « Qualité », « Utilisateurs », menu du compte ouvert,
  page d'administration avec le bandeau de session finie. Incomplets jugés à la main : `aria-valid-attr-value` (les
  `aria-controls` des menus « Parcours » et « Retours d'expérience », dont la liste n'est rendue qu'ouverte) ; `color-contrast`
  sur fond en dégradé (boutons `btn-gradient`, titre `text-gradient`) ; `label-content-name-mismatch` sur des symboles (`⠿` des
  poignées « Déplacer : X », `⋯` des « Actions pour X ») — un symbole n'est pas un libellé textuel, 2.5.3 ne s'applique pas.
- **Contrastes** (calculés sur les couleurs rendues) : « EN » inactif 7,67:1, « FR » actif 18,66:1, liens et texte du pied de
  page 7,67:1, « Supprimer » du backoffice et texte du bandeau (`#ea868f` sur `#212529`) 6,10:1.
- **Bandeau** : déconnexion dans un autre onglet, « Enregistrer » → `PUT` 403 sans jeton, bandeau seul, saisie intacte ;
  `getBoundingClientRect().top` du bandeau 65 px = `--app-header-height` (65px) ; « Connexion » de l'en-tête en nouvel onglet ;
  reconnexion dans l'autre onglet, retour (événement `focus`) → « Session rétablie », **un seul** `GET /api/me` (200), en-tête
  rétabli ; « Enregistrer » → 200 (valeur d'origine remise).
- **Menu du compte** : ouvert à la souris, Échap → focus sur le bouton ; Tab après « Déconnexion » le ferme.
- **Refus réel du backoffice** : compte `ROLE_SUPER` rétrogradé pendant l'édition → « Le serveur refuse cette demande… »,
  « Changer de compte » → `/fr/login` avec `redirect` et `reauth=1`, même onglet ; menu relu au palier de confiance
  (« Administration » retiré, « Télécharger mon CV » présent) ; `/fr/admin/users` → `/fr/forbidden`.
- **Sélecteur de langue** (R227 de la dix-huitième relecture, R235) : `/fr/login?redirect=/fr/admin/about#formulaire` → EN →
  `/en/login?redirect=/en/admin/about#formulaire` ; une cible externe `//evil.example/fr` reste telle quelle.
  **Stockage bloqué** (`setItem` et `getItem` qui lèvent `SecurityError`) : le passage en EN navigue, `lang="en"`, aucune
  erreur en console.
- **320 px** : « Mes connexions MCP » au palier de confiance, FR et EN, et les pages publiques (« À propos », `/stack`, contact,
  contributions, incidents, connexion) : aucun défilement horizontal ; en-tête anonyme EN (marque tronquée, FR, EN, « Accès
  instantané » en icône, « Login », menu) dans la largeur.
- **Cookies de session** : après une connexion et après « Accès instantané », `BEARER` (`httponly; samesite=lax`) et
  `XSRF-TOKEN` (`samesite=lax`) sans `Expires` ni `Max-Age` (en dev, sans `Secure`, comme prévu).

## Non contrôlé ici, et pourquoi

- **Liste, erreur, révocation de « Mes connexions MCP »**, contraste de « Révoquer », focus après révocation : aucune connexion
  MCP n'existe en local (un client OAuth est requis) ; couverts par `McpConnectionsPage.spec.ts`.
- **Consentement OAuth** : même raison (une demande d'autorisation d'un client MCP) ; couvert par `OAuthConsentPage.spec.ts`.
- **Safari, Firefox, lecteur d'écran réel, zoom 200 %, fermeture et réouverture du navigateur, échéance de 15 minutes de
  l'accès de base, réseau ralenti (« Slow 3G »), pile en même origine** : hors de portée du pilotage par l'extension, ou à faire
  en préprod à la release (la pile en même origine est la préprod) ; les scénarios correspondants restent couverts par leurs
  specs (`useAuth.spec.ts`, `AppLayout.spec.ts`, `AccessRefusedNotice.spec.ts`, `singleFlight.spec.ts`) et par les smokes de
  préprod (`smoke-test-preprod`, `audit-preprod`).
- **Les scénarios de proposition de traduction et du verrou de langue** (R78 à R104) : non rejoués, couverts par les specs
  des formulaires d'entrée.
