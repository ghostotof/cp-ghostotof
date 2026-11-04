/**
 * La génération de la session (#748, R14, R26) : un compteur de module, avancé à chaque **fin** acceptée
 * (`markSessionExpired`), à chaque **retour** (la descente de `sessionEnded`) et par **toute session que l'onglet
 * décide lui-même** (`login`, `logout`, `markBaseAccessGranted`, `markBaseAccessExpired` quand il change le palier).
 * Une session *lue* sur `/api/me` n'avance rien : elle est la réponse — sauf quand elle révèle un autre compte
 * hors session finie (`applyLiveSession`, R48 : la page ouverte n'est plus celle du compte qui a envoyé). Un appelant la lit à l'envoi
 * (`sessionStamp()`) et la rend avec le verdict (`markSessionExpired(sentAt)`, la relecture de `/api/me`) : ce qui
 * est parti sous une génération révolue est un verdict d'une session qui n'est plus la courante, et ne la change pas,
 * ni pour la finir ni pour la rétablir. Un seul nombre règle les deux sens, et les lectures parallèles du chargement
 * d'une page (parties sous la même génération) n'annoncent la fin qu'une fois.
 *
 * Module sans dépendance : il ne connaît ni l'état de la session ni ses signaux (#748, R25).
 */
let generation = 0

/**
 * La génération courante de la session : à lire **à l'envoi** d'une requête, pour la rendre avec son verdict
 * (`markSessionExpired(sentAt)`). Voir `generation`.
 */
export function sessionStamp(): number {
  return generation
}

/**
 * Une fin acceptée, un retour ou une session décidée dans l'onglet (connexion, déconnexion, accès de base obtenu ou
 * échu) vient d'avoir lieu : tout ce qui est parti avant devient périmé.
 */
export function advanceGeneration(): void {
  generation += 1
}

/** Vrai quand `sentAt`, lu à l'envoi, est encore la génération courante : rien n'a fini ni rétabli la session depuis. */
export function isCurrentGeneration(sentAt: number): boolean {
  return sentAt === generation
}

/** Réservée aux tests (`resetAuthStateForTests`), jamais appelée par `src/`. */
export function resetSessionGenerationForTests(): void {
  generation = 0
}
