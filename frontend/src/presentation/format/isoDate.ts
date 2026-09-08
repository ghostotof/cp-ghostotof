/**
 * Analyse une date de calendrier ISO (`2027-12-31`) telle que l'expose l'API.
 *
 * Le suffixe `T00:00:00` n'est pas décoratif. Sans lui, une chaîne réduite à
 * une date est interprétée en **UTC** par le moteur ; avec lui, elle l'est en
 * heure locale. La différence se voit à l'écran : pour un visiteur situé à
 * l'ouest de Greenwich, `new Date('2027-12-31')` s'affiche « 30 décembre ». Or
 * le contrat de l'API annonce un jour, pas un instant — le décaler d'un jour
 * serait faux.
 *
 * Retourne `null` sur une valeur illisible, pour que l'appelant retombe sur la
 * chaîne brute plutôt que d'afficher « Invalid Date » : une date mal formée ne
 * doit jamais faire disparaître la ligne qui la porte.
 *
 * Rassemblé ici parce que la règle était écrite trois fois — deux fois dans
 * StackPage, une fois dans IncidentsPage — avec à chaque endroit le même
 * commentaire pour l'expliquer. C'est le signe d'une règle partagée, pas d'une
 * coïncidence.
 */
export function parseIsoDate(isoDate: string): Date | null {
  const parsed = new Date(`${isoDate}T00:00:00`)

  return Number.isNaN(parsed.getTime()) ? null : parsed
}
