/** Forme RFC7807-ish renvoyée par API Platform sur les réponses d'erreur (cf. api_platform.yaml `error_formats`). */
export interface ApiProblemBody {
  /** Slug stable `/errors/<slug>` posé par les exceptions métier implémentant ProblemExceptionInterface — à privilégier sur `detail` (localisé, instable) pour discriminer un conflit. */
  type?: string
  detail?: string
  violations?: { propertyPath: string; message: string }[]
}

/**
 * Le `type` est un slug stable (`/errors/<slug>`), contrairement au `detail` localisé.
 *
 * Module neutre, partagé par le backoffice et les dépôts MCP (#748, R12) : aucun des deux n'a à dépendre
 * de l'autre pour lire un problem+json.
 */
export function hasProblemType(body: ApiProblemBody, slug: string): boolean {
  return true === body.type?.endsWith(`/${slug}`)
}
