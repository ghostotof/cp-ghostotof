# cp-ghostotof

Portfolio d'un développeur dont le contenu se dévoile par paliers : ce qui identifie l'auteur n'est servi
qu'aux personnes à qui il l'a accordé nommément.

Les termes valent en français, langue des issues et des specs ; l'interface anglaise emploie leurs
traductions, le code les noms donnés en _En code_. Un libellé de navigation (« Parcours », « Assistant »,
« CV ») n'est pas un terme du glossaire.

## Paliers d'accès

**Palier d'accès** :
Degré de confiance accordé à la personne qui consulte le site ; il détermine les contenus qu'elle reçoit.
Il y en a trois : anonyme, de base, de confiance.
_À éviter_ : niveau, tier

**Palier anonyme** :
Palier de toute personne qui n'a ni accès de base ni compte.
_À éviter_ : visiteur non connecté, public
_En code_ : `anonymous`

**Palier de base** :
Palier de discrétion, pas de protection : il ne reçoit que du contenu publiable, jamais de donnée
nominative. S'y trouvent l'accès de base et tout compte sans palier de confiance.
_À éviter_ : palier intermédiaire, invité, base tier
_En code_ : `ROLE_USER`, `base`

**Accès de base** :
Autorisation temporaire, sans compte, qui place la personne au palier de base. Elle s'obtient en un clic,
sans identifiant.
_À éviter_ : accès instantané (libellé du bouton), jeton sans compte, compte invité
_En code_ : `BaseAccessUser`

**Palier de confiance** :
Palier qui reçoit les données nominatives. Il n'est accordé qu'à une personne nommée, par un administrateur.
_À éviter_ : palier nominatif, palier supérieur, approuvé
_En code_ : `ROLE_TRUSTED`, `trusted`

**Nominatif** :
Qualifie une donnée ou un compte qui identifie une personne réelle : nom, employeurs, chronologie
professionnelle. Le mot qualifie ce qu'une chose révèle, jamais un palier.
_À éviter_ : identifiant (ambigu avec l'identifiant de connexion)

**Administrateur** :
Rôle de la personne qui gère les contenus et les comptes depuis le backoffice. Ce n'est pas un palier ; il
comprend le palier de confiance.
_À éviter_ : admin, super-utilisateur
_En code_ : `ROLE_SUPER`

**Backoffice** :
Espace réservé à l'administrateur, où se saisissent les contenus et se gèrent les comptes.
_À éviter_ : admin, administration (libellé de l'interface)

**Compte** :
Identité persistante d'une personne nommée, créée par un administrateur. Toujours nominatif : un compte
n'est jamais partagé.
_À éviter_ : compte invité, compte générique, compte partagé, utilisateur
_En code_ : `CpgUser`

**Invitation** :
Acte par lequel un administrateur crée le compte d'une personne nommée et lui envoie par e-mail de quoi
l'activer. Le compte reste en attente jusqu'à son activation ; on dit « compte sur invitation » quand il
faut le distinguer d'un compte créé en ligne de commande.
_À éviter_ : invité, pour désigner un palier ou un compte

## Contenus

**Publiable** :
Critère éditorial — « accepterais-je de publier ceci ? » — qui décide si un contenu peut aller au palier
de base ou partir vers un fournisseur d'IA tiers (seul l'hébergeur du site peut recevoir davantage). Ce
n'est pas un état : il n'existe ni brouillon ni
publication, tout contenu enregistré est servi.
_À éviter_ : publié, brouillon, comme états d'un contenu

**CV nominatif** :
CV de l'auteur avec son identité — nom, employeurs, chronologie professionnelle —, sous forme de document,
réservé au palier de confiance.
_À éviter_ : CV seul, vrai CV, CV complet
_En code_ : `Portfolio/Cv`

**CV sans identité** :
CV publiable organisé par domaines de compétence, sans nom, employeur, client ni chronologie ; contenu du
palier de base.
_À éviter_ : CV anonymisé, CV anonyme, CV seul
_En code_ : `AnonymousCv`

**Domaine de compétence** :
Rubrique du CV sans identité qui regroupe des compétences, une ancienneté et des réalisations.
_À éviter_ : section
_En code_ : `AnonymousCvSection`

**Ancienneté** :
Nombre d'années de pratique d'un domaine de compétence.
_À éviter_ : séniorité
_En code_ : `yearsOfExperience`

**Réalisation** :
Résultat concret obtenu dans un domaine de compétence, formulé sans client ni employeur.
_En code_ : `achievements`

**Étude de cas** :
Récit d'un problème technique réel — contraintes, solution retenue, compromis, résultat mesuré — sans nom
de client ; contenu du palier de base.
_À éviter_ : étude technique, case study
_En code_ : `CaseStudy`

**Chronologie professionnelle** :
Succession datée des postes et des employeurs. Nominative par nature, même privée des noms ou réduite à
des durées et des secteurs : elle se ré-identifie par recoupement.
_À éviter_ : parcours seul, parcours anonymisé

**Parcours technique** :
Technologies pratiquées par l'auteur et leurs années de pratique, sans chronologie ; ouvert au palier anonyme.
_À éviter_ : parcours seul, expériences
_En code_ : `Portfolio/Experience`

## Langues et ordre

**Langue** :
Français ou anglais, les deux langues du site.
_À éviter_ : locale
_En code_ : `Locale`

**Version linguistique** :
Un contenu dans une langue donnée.
_À éviter_ : version seul, traduction (l'original n'en est pas une)

**Groupe de traduction** :
Ensemble des versions linguistiques d'un même contenu, au plus une par langue ; chacune est « version de »
chacune des autres.
_À éviter_ : « translation of »
_En code_ : `translation_group`

**Ordre d'affichage** :
Ordre choisi par l'administrateur pour une liste de contenus.
_À éviter_ : position, placement, classement (réservé à un ordre calculé)
_En code_ : `position`, `Orderable`

**Brouillon d'ordre** :
Ordre d'affichage modifié dans le backoffice et pas encore enregistré ; tant qu'il existe, les autres
modifications de la page sont bloquées.
_En code_ : `useOrderDraft`

## Veille technique

**Veille technique** :
Suivi, publié sur le site, du statut de support et des vulnérabilités connues des produits sur lesquels
le site repose.
_À éviter_ : tech radar, stack (« Ma stack » reste le titre de la page)
_En code_ : `Portfolio/Watch`

**Produit surveillé** :
Logiciel dont la veille technique suit la version, le statut de support et les vulnérabilités.
_À éviter_ : produit suivi, tracked product
_En code_ : `WatchedProduct`

**Relevé** :
État d'un volet de la veille — cycles de version ou vulnérabilités — tel qu'obtenu au dernier
rafraîchissement.
_À éviter_ : snapshot
_En code_ : `WatchSnapshot`

**Statut de support** :
Situation d'une version d'un produit surveillé vis-à-vis de son éditeur : supportée, sécurité uniquement,
fin de vie ou inconnue.
_En code_ : `SupportStatus`

**Fin de vie** :
Statut d'une version que son éditeur ne corrige plus, même pour la sécurité.
_À éviter_ : EOL, obsolète
_En code_ : `eol`

**Manifeste de paquets** :
Liste des paquets et des versions effectivement embarqués par le site ; c'est le périmètre des
vulnérabilités analysées.
_En code_ : `PackageManifest`

## Assistance IA

**Assistant de carrière** :
Assistant conversationnel qui répond aux questions sur la carrière de l'auteur, à partir du CV nominatif,
du CV sans identité et des études de cas ; réservé au palier de confiance.
_À éviter_ : assistant seul, assistant de parcours, « interrogez mon parcours », assistant-parcours
_En code_ : `Ai/Assistant`, `career_assistant`

**Assistant de traduction** :
Outil du backoffice qui propose la version linguistique manquante d'un contenu.
_À éviter_ : assistant seul, traduction automatique
_En code_ : `Ai/Translation`

**Proposition** :
Texte produit par l'assistant de traduction, soumis à l'administrateur et jamais enregistré tant qu'il ne
l'a pas validé.
_À éviter_ : brouillon (réservé au brouillon d'ordre)
_En code_ : `TranslationProposal`

## Accès MCP

**Corpus nominatif** :
Ensemble de ce que le palier de confiance peut lire, assemblé en un seul texte : le contexte de
l'assistant de carrière. Tout canal qui sert le palier de confiance sert ce même corpus, rien de plus.
_À éviter_ : contexte complet, CV complet

**Serveur MCP** :
Canal par lequel l'assistant IA d'une personne interroge le portfolio. Sans authentification, il sert les
contenus des paliers anonyme et de base ; au palier de confiance, le corpus nominatif.

**Client MCP** :
Logiciel d'une personne (Claude, un IDE…) qui se branche sur le serveur MCP pour son compte.
_À éviter_ : application, connecteur (le connecteur est le nom que lui donne un client particulier)

**Connexion MCP** :
Autorisation qu'une personne du palier de confiance accorde à un client MCP de lire le corpus nominatif en
son nom. Elle se révoque, par cette personne ou par la perte du palier de confiance, avec effet immédiat.
_À éviter_ : session MCP, jeton (le jeton est le support technique d'une connexion)
