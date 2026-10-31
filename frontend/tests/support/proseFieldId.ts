/**
 * L'identifiant d'un champ de prose : `<préfixe du formulaire>-<champ en kebab>` (`admin-incident` + `rootCause` donne
 * `admin-incident-root-cause`). La règle que suivent tous les champs de prose du backoffice, écrite une fois pour les specs :
 * ils dérivent de la liste des champs d'une page (`src/presentation/pages/admin/proseFields.ts`) l'identifiant que le
 * formulaire doit rendre. Les gabarits écrivent leurs `id="…"` en littéraux : aucun code de production ne la lit.
 */
export function proseFieldId(prefix: string, field: string): string {
  return `${prefix}-${field.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}`
}
