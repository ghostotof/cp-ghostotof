import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import ContactPage from '../../../src/presentation/pages/ContactPage.vue'
import { CONTACT_REPOSITORY } from '../../../src/application/contact/useContactForm'
import { createAppI18n } from '../../../src/presentation/i18n'
import type { ContactRepository } from '../../../src/domain/contact/repositories/ContactRepository'
import { ContactRateLimitedError } from '../../../src/domain/contact/errors/ContactRateLimitedError'
import { ContactValidationError } from '../../../src/domain/contact/errors/ContactValidationError'
import { expectNoAccessibilityViolation } from '../../support/axe'

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

/** Saisie valide puis envoi : le refus testé vient du repository, pas du formulaire. */
async function submitForm(wrapper: Awaited<ReturnType<typeof mountContactPage>>): Promise<void> {
  await wrapper.find('#contact-name').setValue('Jane Doe')
  await wrapper.find('#contact-email').setValue('jane@example.com')
  await wrapper.find('#contact-message').setValue('Bonjour, je vous contacte au sujet de...')
  await wrapper.find('form').trigger('submit.prevent')
  await flushPromises()
}

describe('ContactPage', () => {
  it("n'expose aucune adresse email en clair : le formulaire couvre le besoin", async () => {
    const wrapper = await mountContactPage()

    // Régression : la page affichait l'adresse de contact dans un bouton
    // mailto. Une adresse en clair dans le HTML d'une page publique est
    // moissonnée par les robots à spam, et le formulaire (avec honeypot et
    // limitation de débit côté API) remplit exactement la même fonction.
    expect(wrapper.find('a[href^="mailto:"]').exists()).toBe(false)
    expect(wrapper.html()).not.toMatch(/[\w.+-]+@[\w-]+\.[\w.]+/)
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

    await submitForm(wrapper)

    const alert = wrapper.find('[role="alert"]')
    expect(alert.exists()).toBe(true)
    expect(alert.text()).toContain('Réessayez plus tard')
  })

  it("signale le champ refusé sous le champ lui-même et l'y relie par aria-describedby", async () => {
    // Régression #236 : un 422 s'affichait comme « L'envoi du message a échoué,
    // réessayez plus tard », invitant le visiteur à réémettre à l'identique une
    // saisie que le serveur refusera toujours.
    const repository = createStubRepository({
      submit: vi.fn(async () =>
        Promise.reject(new ContactValidationError([{ propertyPath: 'message', message: 'Votre message est trop court.' }])),
      ),
    })
    const wrapper = await mountContactPage(repository)

    await submitForm(wrapper)

    const error = wrapper.find('#contact-message-error')
    expect(error.exists()).toBe(true)
    expect(error.text()).toContain('entre 10 et 5000 caractères')

    // L'aide de saisie reste dans aria-describedby, l'erreur s'y ajoute après
    // elle : la règle d'abord, le refus ensuite.
    const textarea = wrapper.find('#contact-message')
    expect(textarea.attributes('aria-describedby')).toBe('contact-message-hint contact-message-error')
    expect(textarea.attributes('aria-invalid')).toBe('true')

    // Les champs acceptés ne sont ni signalés ni reliés à un message absent —
    // le nom garde sa seule aide de saisie.
    expect(wrapper.find('#contact-name-error').exists()).toBe(false)
    expect(wrapper.find('#contact-name').attributes('aria-describedby')).toBe('contact-name-hint')

    // Le bloc global reste présent et renvoie vers les champs signalés.
    expect(wrapper.find('[role="alert"]').text()).toContain('corrigez les champs signalés')
  })

  it("n'affiche pas le libellé du backend, écrit en français en dur, mais le sien", async () => {
    const repository = createStubRepository({
      submit: vi.fn(async () =>
        Promise.reject(new ContactValidationError([{ propertyPath: 'message', message: 'Votre message est trop court.' }])),
      ),
    })
    const wrapper = await mountContactPage(repository)

    await submitForm(wrapper)

    expect(wrapper.text()).not.toContain('Votre message est trop court.')
  })

  it('retombe sur un message de validation générique si aucune violation exploitable ne revient', async () => {
    const repository = createStubRepository({
      submit: vi.fn(async () => Promise.reject(new ContactValidationError([]))),
    })
    const wrapper = await mountContactPage(repository)

    await submitForm(wrapper)

    expect(wrapper.find('[role="alert"]').text()).toContain('Votre saisie a été refusée')
  })

  it("ne révèle rien d'un refus portant sur le honeypot : message générique et aucun champ signalé", async () => {
    // Le honeypot (`website`) est masqué et retiré des technologies
    // d'assistance : si le serveur refusait la saisie à cause de lui, afficher
    // « corrigez les champs signalés » ou un message sous un champ nommé
    // `website` apprendrait au bot que le piège existe. Rien ne doit remonter
    // à la surface hormis le message générique.
    const repository = createStubRepository({
      submit: vi.fn(async () =>
        Promise.reject(new ContactValidationError([{ propertyPath: 'website', message: 'Votre site est trop long.' }])),
      ),
    })
    const wrapper = await mountContactPage(repository)

    await submitForm(wrapper)

    expect(wrapper.find('[role="alert"]').text()).toContain('Votre saisie a été refusée')
    expect(wrapper.findAll('[id$="-error"]')).toHaveLength(0)
  })

  it('distingue la limitation de débit : rien à corriger, seulement à attendre', async () => {
    const repository = createStubRepository({
      submit: vi.fn(async () => Promise.reject(new ContactRateLimitedError())),
    })
    const wrapper = await mountContactPage(repository)

    await submitForm(wrapper)

    expect(wrapper.find('[role="alert"]').text()).toContain('Réessayez dans quelques minutes')
    expect(wrapper.find('#contact-message-error').exists()).toBe(false)
  })

  it('porte les bornes du Validator backend sur les champs (minlength/maxlength)', async () => {
    const wrapper = await mountContactPage()

    expect(wrapper.find('#contact-name').attributes('minlength')).toBe('2')
    expect(wrapper.find('#contact-name').attributes('maxlength')).toBe('100')
    expect(wrapper.find('#contact-email').attributes('maxlength')).toBe('255')
    expect(wrapper.find('#contact-message').attributes('minlength')).toBe('10')
    expect(wrapper.find('#contact-message').attributes('maxlength')).toBe('5000')
  })

  it('affiche la borne minimale en clair sous le nom et sous le message, sans attendre un refus', async () => {
    // Le formulaire porte `novalidate` (bulles natives dans la langue du
    // navigateur, pas du site) : `minlength` ne bloque donc plus rien, il est
    // déclaratif. La prévention effective, c'est cette aide visible, reliée en
    // permanence au champ par aria-describedby.
    const wrapper = await mountContactPage()

    expect(wrapper.find('#contact-name-hint').text()).toContain('2 caractères minimum')
    expect(wrapper.find('#contact-name').attributes('aria-describedby')).toBe('contact-name-hint')
    expect(wrapper.find('#contact-message-hint').text()).toContain('10 caractères minimum')
    expect(wrapper.find('#contact-message').attributes('aria-describedby')).toBe('contact-message-hint')
  })

  it("ne relève aucune violation d'accessibilité, y compris avec un champ signalé", async () => {
    const repository = createStubRepository({
      submit: vi.fn(async () =>
        Promise.reject(new ContactValidationError([{ propertyPath: 'message', message: 'Votre message est trop court.' }])),
      ),
    })
    const wrapper = await mountContactPage(repository)
    await expectNoAccessibilityViolation(wrapper)

    await submitForm(wrapper)

    await expectNoAccessibilityViolation(wrapper)
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
