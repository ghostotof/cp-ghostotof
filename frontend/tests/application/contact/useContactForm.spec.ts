import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { CONTACT_REPOSITORY, useContactForm } from '../../../src/application/contact/useContactForm'
import type { ContactRepository } from '../../../src/domain/contact/repositories/ContactRepository'

function createStubRepository(overrides: Partial<ContactRepository> = {}): ContactRepository {
  return {
    submit: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: ContactRepository) {
  let captured: ReturnType<typeof useContactForm> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useContactForm()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [CONTACT_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useContactForm() did not run during mount')
  }

  return captured
}

describe('useContactForm', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useContactForm()
        return () => h('div')
      },
    })

    expect(() => mount(Probe)).toThrow(/ContactRepository/)
  })

  it('submit() transmet les champs saisis au repository et bascule isSuccess à true', async () => {
    const repository = createStubRepository()
    const form = mountWithComposable(repository)
    form.name.value = 'Jane Doe'
    form.email.value = 'jane@example.com'
    form.message.value = 'Bonjour !'

    const pending = form.submit()
    expect(form.isSubmitting.value).toBe(true)
    await pending

    expect(repository.submit).toHaveBeenCalledWith({
      name: 'Jane Doe',
      email: 'jane@example.com',
      message: 'Bonjour !',
      honeypot: '',
    })
    expect(form.isSuccess.value).toBe(true)
    expect(form.hasError.value).toBe(false)
    expect(form.isSubmitting.value).toBe(false)
  })

  it('submit() vide les champs après un envoi réussi', async () => {
    const form = mountWithComposable(createStubRepository())
    form.name.value = 'Jane Doe'
    form.email.value = 'jane@example.com'
    form.message.value = 'Bonjour !'

    await form.submit()

    expect(form.name.value).toBe('')
    expect(form.email.value).toBe('')
    expect(form.message.value).toBe('')
  })

  it('submit() bascule hasError à true si le repository échoue, sans lever et sans vider le formulaire', async () => {
    const repository = createStubRepository({ submit: vi.fn(async () => Promise.reject(new Error('failed'))) })
    const form = mountWithComposable(repository)
    form.name.value = 'Jane Doe'

    await form.submit()

    expect(form.hasError.value).toBe(true)
    expect(form.isSuccess.value).toBe(false)
    expect(form.isSubmitting.value).toBe(false)
    expect(form.name.value).toBe('Jane Doe')
  })
})
