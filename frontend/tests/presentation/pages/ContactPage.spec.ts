import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import ContactPage from '../../../src/presentation/pages/ContactPage.vue'
import { CONTACT_REPOSITORY } from '../../../src/application/contact/useContactForm'
import { createAppI18n } from '../../../src/presentation/i18n'
import type { ContactRepository } from '../../../src/domain/contact/repositories/ContactRepository'

const StubPage = { template: '<div />' }

function createStubRepository(overrides: Partial<ContactRepository> = {}): ContactRepository {
  return {
    submit: vi.fn(async () => undefined),
    ...overrides,
  }
}

/**
 * ContactPage dépend de Vue Router (lien vers la politique de confidentialité,
 * cf. mention RGPD sous le formulaire) : on lui fournit une vraie instance,
 * même pattern que tests/presentation/layout/AppHeader.spec.ts.
 */
async function mountContactPage(repository: ContactRepository = createStubRepository()) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)/contact', name: 'contact', component: StubPage },
      { path: '/:locale(fr|en)/privacy-policy', name: 'privacy-policy', component: StubPage },
    ],
  })
  await router.push('/fr/contact')
  await router.isReady()

  return mount(ContactPage, {
    global: {
      plugins: [router, createAppI18n()],
      provide: { [CONTACT_REPOSITORY as symbol]: repository },
    },
  })
}

describe('ContactPage', () => {
  it("affiche l'adresse de contact liée au site plutôt qu'une adresse personnelle, sous forme de lien mailto", async () => {
    const wrapper = await mountContactPage()

    const mailLink = wrapper.find('a[href^="mailto:"]')
    expect(mailLink.exists()).toBe(true)
    expect(mailLink.attributes('href')).toBe('mailto:contact@cp-ghostotof.com')
    expect(wrapper.text()).toContain('contact@cp-ghostotof.com')
    // Ne divulgue jamais l'adresse email personnelle derrière l'alias.
    expect(wrapper.html()).not.toMatch(/@gmail\.com/)
  })

  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', async () => {
    const wrapper = await mountContactPage()

    expect(wrapper.find('h1').exists()).toBe(true)
  })

  it('affiche une mention RGPD sous le formulaire, avec un lien vers la politique de confidentialité', async () => {
    const wrapper = await mountContactPage()

    const privacyLink = wrapper.find('a[href="/fr/privacy-policy"]')
    expect(privacyLink.exists()).toBe(true)
  })

  it('soumet le formulaire avec les champs saisis et affiche un message de succès', async () => {
    const repository = createStubRepository()
    const wrapper = await mountContactPage(repository)

    await wrapper.find('#contact-name').setValue('Jane Doe')
    await wrapper.find('#contact-email').setValue('jane@example.com')
    await wrapper.find('#contact-message').setValue('Bonjour, je vous contacte au sujet de...')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.submit).toHaveBeenCalledWith({
      name: 'Jane Doe',
      email: 'jane@example.com',
      message: 'Bonjour, je vous contacte au sujet de...',
      honeypot: '',
    })
    expect(wrapper.find('[role="status"]').exists()).toBe(true)
  })

  it("affiche un message d'erreur générique si l'envoi échoue, sans exposer le détail technique", async () => {
    const repository = createStubRepository({ submit: vi.fn(async () => Promise.reject(new Error('500'))) })
    const wrapper = await mountContactPage(repository)

    await wrapper.find('#contact-name').setValue('Jane Doe')
    await wrapper.find('#contact-email').setValue('jane@example.com')
    await wrapper.find('#contact-message').setValue('Bonjour, je vous contacte au sujet de...')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('expose un champ honeypot anti-spam masqué et inatteignable au clavier', async () => {
    const wrapper = await mountContactPage()

    const honeypot = wrapper.find('#contact-website')
    expect(honeypot.exists()).toBe(true)
    expect(honeypot.attributes('tabindex')).toBe('-1')
    expect(honeypot.attributes('autocomplete')).toBe('off')
    expect(honeypot.element.closest('[aria-hidden="true"]')).not.toBeNull()
  })
})
