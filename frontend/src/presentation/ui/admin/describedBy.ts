/**
 * Les identifiants non vides, joints par une espace (la forme d'un `aria-describedby`) ; `undefined`
 * s'il n'y en a aucun, pour que l'attribut ne soit pas rendu.
 *
 * Partagé par tous ses appelants : le verrou d'un formulaire (`useEntryFormLock`), la prose d'un formulaire traduit
 * (`useTranslatedForm`), le bouton de traduction (`TranslateEntryButton`) et le bouton « Proposer la version XX » de chaque
 * page ou section de formulaire (`AdminIncidentsPage`, `AdminContributionsPage`, `AdminCaseStudiesPage`,
 * `AdminAnonymousCvPage`, `AdminQualityPrinciplesSection`, `AdminQualityTraitsSection`, `AdminAboutSiteCardsSection`,
 * `AdminAboutMeCardsSection`, `AdminAboutSettingsForm`) : un seul endroit écrit la forme.
 */
export function describedBy(...ids: ReadonlyArray<string | undefined>): string | undefined {
  const present = ids.filter((id): id is string => undefined !== id && '' !== id)

  return 0 === present.length ? undefined : present.join(' ')
}
