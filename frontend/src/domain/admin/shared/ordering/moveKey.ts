/**
 * Déplace une clé de `from` vers `to` dans une nouvelle liste (l'original
 * n'est jamais modifié) : c'est le seul geste dont `useOrderDraft` a besoin
 * pour construire un brouillon d'ordre, que ce déplacement vienne du
 * glisser-déposer ou du clavier (`OrderHandle`).
 *
 * Hors bornes ou `from === to` : une copie inchangée, jamais une exception —
 * un déplacement invalide (ex. relâché hors du tableau) ne doit pas casser
 * le brouillon en cours.
 */
export function moveKey(keys: readonly string[], from: number, to: number): string[] {
  const copy = [...keys]

  if (from < 0 || from >= copy.length || to < 0 || to >= copy.length || from === to) {
    return copy
  }

  const [moved] = copy.splice(from, 1)
  copy.splice(to, 0, moved)

  return copy
}
