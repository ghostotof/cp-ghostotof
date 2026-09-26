import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { CONTACT_REPOSITORY, useContactForm } from '../../../src/application/contact/useContactForm'
import type { ContactRepository } from '../../../src/domain/contact/repositories/ContactRepository'
import { ContactRateLimitedError } from '../../../src/domain/contact/errors/ContactRateLimitedError'
import { ContactValidationError } from '../../../src/domain/contact/errors/ContactValidationError'

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
    expect(form.errorReason.value).toBeNull()
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

  it("submit() expose la raison « unknown » sur un échec quelconque, sans lever et sans vider le formulaire", async () => {
    const repository = createStubRepository({ submit: vi.fn(async () => Promise.reject(new Error('failed'))) })
    const form = mountWithComposable(repository)
    form.name.value = 'Jane Doe'

    await form.submit()

    expect(form.errorReason.value).toBe('unknown')
    expect(form.fieldErrors.value).toEqual(new Set())
    expect(form.isSuccess.value).toBe(false)
    expect(form.isSubmitting.value).toBe(false)
    expect(form.name.value).toBe('Jane Doe')
  })

  it('submit() expose la raison « validation » et les champs refusés sur un ContactValidationError', async () => {
    const repository = createStubRepository({
      submit: vi.fn(async () =>
        Promise.reject(
          new ContactValidationError([
            { propertyPath: 'message', message: 'Votre message est trop court.' },
            { propertyPath: 'name', message: 'Votre nom est trop court.' },
          ]),
        ),
      ),
    })
    const form = mountWithComposable(repository)

    await form.submit()

    expect(form.errorReason.value).toBe('validation')
    expect(form.fieldErrors.value).toEqual(new Set(['message', 'name']))
  })

  it('submit() expose la raison « rate-limited » sur un ContactRateLimitedError, sans champ signalé', async () => {
    const repository = createStubRepository({
      submit: vi.fn(async () => Promise.reject(new ContactRateLimitedError())),
    })
    const form = mountWithComposable(repository)

    await form.submit()

    expect(form.errorReason.value).toBe('rate-limited')
    expect(form.fieldErrors.value).toEqual(new Set())
  })

  it('submit() remet la raison et les champs signalés à zéro dès la soumission suivante', async () => {
    // Sans cette remise à zéro, les messages sous les champs survivraient à la
    // correction : le visiteur corrigerait une saisie déjà acceptée.
    const submit = vi
      .fn<ContactRepository['submit']>()
      .mockRejectedValueOnce(new ContactValidationError([{ propertyPath: 'message', message: 'trop court' }]))
      .mockResolvedValueOnce(undefined)
    const form = mountWithComposable(createStubRepository({ submit }))

    await form.submit()
    expect(form.errorReason.value).toBe('validation')

    const pending = form.submit()
    expect(form.errorReason.value).toBeNull()
    expect(form.fieldErrors.value).toEqual(new Set())
    await pending

    expect(form.isSuccess.value).toBe(true)
    expect(form.errorReason.value).toBeNull()
    expect(form.fieldErrors.value).toEqual(new Set())
  })
})
