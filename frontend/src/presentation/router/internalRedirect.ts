/**
 * La cible de retour que la page de connexion accepte (`?redirect=`, posée par
 * le garde de route et par les liens de reconnexion) : **un chemin interne de
 * ce site, et rien d'autre** (#650, F-I1 de la seconde relecture). Un paramètre
 * d'URL est une entrée de l'attaquant : une cible hors du site ferait de la page
 * de connexion une redirection ouverte, un lien de phishing portant le nom du
 * site.
 *
 * Le piège n'est pas `//hôte` seul (déjà refusé) mais les **différentiels de
 * lecture** : la chaîne que l'on juge doit vouloir dire la même chose pour tous
 * ceux qui la liront. Un navigateur lit `\` comme `/` dans une URL `http(s)`
 * (`/\hôte` est `//hôte`) et retire silencieusement tabulation et sauts de ligne
 * (`/<TAB>/hôte` est `//hôte`). Une garde qui ne regarde que le début de la
 * chaîne laisse passer ces deux formes ; si `/\hôte` ne ressort pas aujourd'hui,
 * ce n'est que par un détail interne de vue-router (`router.push` d'une chaîne
 * la résout contre l'origine courante), sur lequel rien ne doit reposer.
 *
 * **Choix : refuser la barre oblique inverse et les caractères de contrôle**,
 * plutôt que résoudre la cible avec l'analyseur d'URL et comparer son origine.
 * La règle est alors une propriété de la chaîne reçue, la même qu'on passera au
 * routeur : aucune lecture ne peut la comprendre autrement qu'une autre. La
 * comparaison d'origine, elle, juge la forme *normalisée* (`/a\b` ressort
 * `/a/b`) et non la chaîne que le routeur recevrait, dépend d'un analyseur qui
 * diffère d'un environnement à l'autre (navigateur, jsdom), et — mesuré — ne
 * refuse **rien** de plus une fois la règle syntaxique posée : un chemin qui
 * commence par une seule `/`, sans `\` ni contrôle, reste sur l'origine pour
 * l'analyseur WHATWG. Un second garde sans effet démontrable n'est pas ajouté.
 *
 * Une cible `%5C` (barre oblique inverse encodée) n'est **pas** refusée : un
 * navigateur ne la décode pas dans le chemin, elle reste sur l'origine.
 *
 * @returns la cible telle qu'elle a été reçue (jamais normalisée : la requête et
 *          l'ancre du retour, comme celles d'une demande de consentement, restent
 *          intactes), ou `null` si ce n'est pas un chemin interne
 */
export function internalRedirectTarget(candidate: unknown): string | null {
  if ('string' !== typeof candidate || !candidate.startsWith('/') || candidate.startsWith('//')) {
    return null
  }

  return hasBackslashOrControlCharacter(candidate) ? null : candidate
}

/** Les contrôles C0 (0x00 à 0x1F, tabulation et sauts de ligne compris), DEL (0x7F) et la barre oblique inverse. */
function hasBackslashOrControlCharacter(value: string): boolean {
  for (let index = 0; index < value.length; index += 1) {
    const code = value.charCodeAt(index)
    if (code <= 0x1f || 0x7f === code || 0x5c === code) {
      return true
    }
  }
  return false
}
