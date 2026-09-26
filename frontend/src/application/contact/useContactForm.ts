import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { ContactRepository } from '../../domain/contact/repositories/ContactRepository'
import { ContactRateLimitedError } from '../../domain/contact/errors/ContactRateLimitedError'
import { ContactValidationError, type ContactViolation } from '../../domain/contact/errors/ContactValidationError'

export const CONTACT_REPOSITORY: InjectionKey<ContactRepository> = Symbol('ContactRepository')

/**
 * Catégorise l'échec pour que la présentation choisisse le message traduit
 * (cf. i18n `contact.form.errors.*`) sans connaître le transport :
 * `validation` (422, saisie à corriger), `rate-limited` (429, trop d'envois
 * depuis cette adresse), `unknown` (réseau, 5xx — le seul cas où « réessayez
 * plus tard » a un sens).
 */
export type ContactErrorReason = 'validation' | 'rate-limited' | 'unknown'

export interface UseContactFormResult {
  name: Ref<string>
  email: Ref<string>
  message: Ref<string>
  /** Honeypot anti-spam, jamais affiché ni rempli par un humain (cf. ContactPage.vue). */
  honeypot: Ref<string>
  isSubmitting: Ref<boolean>
  isSuccess: Ref<boolean>
  errorReason: Ref<ContactErrorReason | null>
  /**
   * Ensemble des `propertyPath` refusés par le serveur sur un 422. Un Set
   * plutôt qu'un Record<string, true> : `noUncheckedIndexedAccess` n'étant pas
   * activé, l'indexation d'un Record rendait le type `true` pour n'importe
   * quelle clé, y compris absente — le type affirmait donc le contraire de ce
   * qu'on interroge. `has()` ne ment pas.
   *
   * On ne porte pas le libellé du backend : il est en français en dur (cf.
   * ContactValidationError), c'est la page qui traduit champ par champ.
   */
  fieldErrors: Ref<ReadonlySet<string>>
  submit: () => Promise<void>
}

/**
 * Pas de dépendance à useI18n() ici : le composable expose une raison, la page
 * traduit — même schéma que useAdminTranslation (ADR 0004, D4).
 */
export function useContactForm(): UseContactFormResult {
  const repository = inject(CONTACT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "ContactRepository n'a pas été fourni. Vérifiez que app.provide(CONTACT_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const name = ref('')
  const email = ref('')
  const message = ref('')
  const honeypot = ref('')
  const isSubmitting = ref(false)
  const isSuccess = ref(false)
  const errorReason = ref<ContactErrorReason | null>(null)
  // Un nouveau Set est réassigné à chaque changement d'état, jamais muté :
  // Vue suit la référence portée par le ref, une mutation en place ne
  // redéclencherait pas le rendu des messages sous les champs.
  const fieldErrors = ref<ReadonlySet<string>>(new Set())

  const submit = async (): Promise<void> => {
    errorReason.value = null
    fieldErrors.value = new Set()
    isSuccess.value = false
    isSubmitting.value = true

    try {
      await repository.submit({ name: name.value, email: email.value, message: message.value, honeypot: honeypot.value })
      isSuccess.value = true
      name.value = ''
      email.value = ''
      message.value = ''
    } catch (error) {
      if (error instanceof ContactValidationError) {
        errorReason.value = 'validation'
        fieldErrors.value = toFieldErrors(error.violations)
      } else {
        errorReason.value = error instanceof ContactRateLimitedError ? 'rate-limited' : 'unknown'
      }
    } finally {
      isSubmitting.value = false
    }
  }

  return { name, email, message, honeypot, isSubmitting, isSuccess, errorReason, fieldErrors, submit }
}

function toFieldErrors(violations: ReadonlyArray<ContactViolation>): ReadonlySet<string> {
  return new Set(violations.map((violation) => violation.propertyPath))
}
