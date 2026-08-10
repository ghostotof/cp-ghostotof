export interface ContactMessageInput {
  readonly name: string
  readonly email: string
  readonly message: string
  /**
   * Honeypot anti-spam : champ que seul un bot de formulaire remplit (invisible
   * pour un humain, cf. ContactPage.vue). Doit toujours arriver vide au
   * repository ; c'est le backend (App\Contact\Presentation\ApiResource\ContactMessageResource)
   * qui décide quoi en faire.
   */
  readonly honeypot: string
}
