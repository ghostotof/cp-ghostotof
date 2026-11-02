import { isSupportedLocale, type Locale } from '../../../domain/portfolio/entities/Locale'

/**
 * Lit la langue d'une réponse du backoffice comme une `Locale`, ou lève l'erreur de son contexte (#748, R214, D2).
 *
 * Le backend n'émet que `fr` et `en` (value object `Locale`) : une autre valeur est une rupture du contrat de l'API, que le
 * dépôt dit **à la frontière** plutôt que de la laisser entrer dans une entité typée `Locale` par une assertion (`as Locale`
 * ne juge rien : le type mentirait, et chaque lecteur en aval devrait remettre une garde). Le dépôt passe l'erreur de son
 * contexte (`AdminIncidentError`, `AdminQualityError`…, raison `unknown`) : la page montre son erreur habituelle de
 * chargement ou d'enregistrement, aucune clé de traduction n'est nécessaire.
 *
 * Accepté (Q1 (A)) : une seule entrée fautive fait échouer toute la liste, et une réponse d'écriture refusée ici arrive
 * après que le serveur a écrit. Le contrat l'exclut ; cacher l'entrée (la liste qui l'écarte) la ferait disparaître du
 * backoffice sans un mot.
 *
 * @param unsupported construit l'erreur du contexte à partir de la valeur reçue (qu'elle n'a pas à citer : une valeur du
 *                    serveur n'a rien à faire dans un message)
 */
export function supportedLocaleOf(value: string, unsupported: (value: string) => Error): Locale {
  if (!isSupportedLocale(value)) {
    throw unsupported(value)
  }

  return value
}
