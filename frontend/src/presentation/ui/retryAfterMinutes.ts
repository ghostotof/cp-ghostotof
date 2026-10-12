/**
 * Le délai d'un 429 tel qu'on l'annonce : en minutes entières, arrondies au
 * supérieur (dire « 14 minutes » pour 14 min 30 s ferait réessayer trop tôt),
 * jamais moins d'une — « dans 0 minute » ne se dit pas, et le serveur peut
 * annoncer quelques secondes.
 *
 * Partagé par les pages qui annoncent un délai (l'assistant, la connexion
 * depuis l'issue #399), pour qu'elles arrondissent de la même façon.
 */
export function retryAfterMinutes(retryAfterSeconds: number): number {
  return Math.max(1, Math.ceil(retryAfterSeconds / 60))
}
