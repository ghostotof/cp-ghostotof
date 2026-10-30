import type { VueWrapper } from '@vue/test-utils'

/**
 * Les textes qui expliquent un sélecteur verrouillé d'un formulaire par entrée (#748, D-a et D-b
 * de l'auteur, 2026-10-30), écrits ici une fois pour les huit formulaires et la catégorie des
 * cartes « moi ». Lus par `describedTexts()` : un test dit « ce texte décrit ce sélecteur »,
 * jamais « cet id existe ».
 */
export const LOCALE_LOCKED_EDITING_TEXT = "La langue d'une entrée existante ne peut pas être modifiée."
export const LOCALE_LOCKED_PROPOSAL_TEXT =
  'La langue est celle de la proposition de traduction ; « Annuler » permet d\'en changer.'
export const CATEGORY_LOCKED_PROPOSAL_TEXT =
  'La catégorie est celle de la proposition de traduction ; « Annuler » permet d\'en changer.'

/**
 * Les textes que désigne l'`aria-describedby` du champ `selector`, dans l'ordre des identifiants ;
 * `[]` sans attribut. **Un identifiant qui ne désigne aucun élément fait échouer le test** : une
 * IDREF pendante est elle-même une faute d'accessibilité (`.claude/rules/frontend-a11y.md`).
 */
export function describedTexts(wrapper: VueWrapper, selector: string): string[] {
  const reference = wrapper.get(selector).attributes('aria-describedby')
  if (undefined === reference) {
    return []
  }

  return reference
    .split(/\s+/)
    .filter((id) => '' !== id)
    .map((id) => {
      const target = wrapper.find(`#${id}`)
      if (!target.exists()) {
        throw new Error(`${selector} : aria-describedby désigne #${id}, qui n'existe pas.`)
      }

      return target.text()
    })
}

/** L'aide permanente des sélecteurs de langue des principes, traits et cartes (#582), vraie en toute circonstance. */
export const LOCALE_HELP_TEXT =
  "Langue de cette entrée : c'est elle qui sera enregistrée, et celle sur laquelle porte l'assistant de traduction. Le tableau ci-dessous, lui, affiche toutes les langues."
