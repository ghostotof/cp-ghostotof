/**
 * Les identifiants non vides, joints par une espace (la forme d'un `aria-describedby`) ; `undefined`
 * s'il n'y en a aucun, pour que l'attribut ne soit pas rendu.
 *
 * Un seul endroit écrit cette forme : tout `aria-describedby` composé de plusieurs identifiants passe par ici.
 */
export function describedBy(...ids: ReadonlyArray<string | undefined>): string | undefined {
  const present = ids.filter((id): id is string => undefined !== id && '' !== id)

  return 0 === present.length ? undefined : present.join(' ')
}
