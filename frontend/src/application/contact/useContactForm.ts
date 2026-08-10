import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { ContactRepository } from '../../domain/contact/repositories/ContactRepository'

export const CONTACT_REPOSITORY: InjectionKey<ContactRepository> = Symbol('ContactRepository')

export interface UseContactFormResult {
  name: Ref<string>
  email: Ref<string>
  message: Ref<string>
  /** Honeypot anti-spam, jamais affiché ni rempli par un humain (cf. ContactPage.vue). */
  honeypot: Ref<string>
  isSubmitting: Ref<boolean>
  isSuccess: Ref<boolean>
  hasError: Ref<boolean>
  submit: () => Promise<void>
}

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
  const hasError = ref(false)

  const submit = async (): Promise<void> => {
    hasError.value = false
    isSuccess.value = false
    isSubmitting.value = true

    try {
      await repository.submit({ name: name.value, email: email.value, message: message.value, honeypot: honeypot.value })
      isSuccess.value = true
      name.value = ''
      email.value = ''
      message.value = ''
    } catch {
      hasError.value = true
    } finally {
      isSubmitting.value = false
    }
  }

  return { name, email, message, honeypot, isSubmitting, isSuccess, hasError, submit }
}
