/**
 * Le délai d'un 429, lu dans `Retry-After` : des secondes entières, ou une
 * date HTTP. Absent (les zones nginx n'en posent pas) ou illisible → null, et
 * l'appelant retombe sur un message sans délai.
 *
 * Partagé par les dépôts dont la page annonce le délai (l'assistant, la
 * connexion depuis l'issue #399) : un seul endroit sait lire l'en-tête.
 */
export function retryAfterSeconds(response: Response): number | null {
  const header = response.headers.get('Retry-After')?.trim()
  if (!header) {
    return null
  }
  if (/^\d+$/.test(header)) {
    return Number(header)
  }

  const date = Date.parse(header)

  return Number.isNaN(date) ? null : Math.max(0, Math.ceil((date - Date.now()) / 1000))
}
