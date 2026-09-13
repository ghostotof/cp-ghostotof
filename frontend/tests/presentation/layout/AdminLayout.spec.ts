import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { nextTick } from 'vue'
import AdminLayout from '../../../src/presentation/layout/AdminLayout.vue'
import { createAppI18n } from '../../../src/presentation/i18n'
import { CV_REPOSITORY } from '../../../src/application/cv/useCvDownload'
import type { CvRepository } from '../../../src/domain/cv/repositories/CvRepository'

const StubPage = { template: '<div />' }

/**
 * AdminLayout est monté directement (comme AppHeader.spec) : les routes sont
 * déclarées à plat, sans AdminLayout comme composant parent, pour éviter que
 * son propre `<RouterView>` ne re-rende le layout de façon récursive.
 */
function createTestRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)/admin/technologies', name: 'admin-technologies', component: StubPage },
      { path: '/:locale(fr|en)/admin/about', name: 'admin-about', component: StubPage },
      { path: '/:locale(fr|en)/admin/quality', name: 'admin-quality', component: StubPage },
      { path: '/:locale(fr|en)/admin/contributions', name: 'admin-contributions', component: StubPage },
      { path: '/:locale(fr|en)/admin/incidents', name: 'admin-incidents', component: StubPage },
      { path: '/:locale(fr|en)/admin/anonymous-cv', name: 'admin-anonymous-cv', component: StubPage },
      { path: '/:locale(fr|en)/admin/watch', name: 'admin-watch', component: StubPage },
      { path: '/:locale(fr|en)/admin/users', name: 'admin-users', component: StubPage },
    ],
  })
}

function createStubCvRepository(overrides: Partial<CvRepository> = {}): CvRepository {
  return {
    download: vi.fn(async () => ({ blob: new Blob(['%PDF-1.4'], { type: 'application/pdf' }), filename: 'cv.pdf' })),
    ...overrides,
  }
}

async function mountLayout(initialPath: string, cvRepository: CvRepository = createStubCvRepository()) {
  const router = createTestRouter()
  await router.push(initialPath)
  await router.isReady()

  const wrapper = mount(AdminLayout, {
    attachTo: document.body,
    global: { plugins: [router, createAppI18n()], provide: { [CV_REPOSITORY as symbol]: cvRepository } },
  })
  await wrapper.vm.$nextTick()

  return wrapper
}

function contentToggle(wrapper: Awaited<ReturnType<typeof mountLayout>>) {
  return wrapper.get('button[aria-haspopup="true"]')
}

describe('AdminLayout', () => {
  it('n\'a qu\'une seule barre de navigation de 1er niveau (plus de ligne de sous-menu séparée)', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    expect(wrapper.findAll('nav')).toHaveLength(1)
    wrapper.unmount()
  })

  it('« Contenu » est un bouton de menu, fermé par défaut', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    const toggle = contentToggle(wrapper)
    expect(toggle.text()).toBe('Contenu')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(wrapper.findAll('.dropdown-item')).toHaveLength(0)

    wrapper.unmount()
  })

  it('un clic sur « Contenu » ouvre le menu déroulant avec les liens de section', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    await contentToggle(wrapper).trigger('click')

    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('true')
    const items = wrapper.findAll('.dropdown-item')
    expect(items.map((item) => item.text())).toEqual([
      'Technologies',
      'À propos',
      'Qualité',
      'Contributions',
      'Incidents',
      'CV sans identité',
      'Veille',
    ])

    wrapper.unmount()
  })

  it('un clic sur un lien du menu referme le menu', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    await contentToggle(wrapper).trigger('click')
    await wrapper.get('.dropdown-item').trigger('click')

    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('false')
    expect(wrapper.findAll('.dropdown-item')).toHaveLength(0)

    wrapper.unmount()
  })

  it('la touche Échap referme le menu', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    await contentToggle(wrapper).trigger('click')
    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('true')

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await nextTick()

    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('un clic en dehors du menu le referme', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    await contentToggle(wrapper).trigger('click')
    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('true')

    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()

    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('sur une route de contenu : « Contenu » porte le style actif et, à l\'ouverture, la section courante est marquée aria-current', async () => {
    const wrapper = await mountLayout('/fr/admin/quality')

    expect(contentToggle(wrapper).classes()).toContain('btn-gradient')

    await contentToggle(wrapper).trigger('click')
    const active = wrapper.findAll('.dropdown-item').find((item) => item.text() === 'Qualité')
    expect(active?.attributes('aria-current')).toBe('page')

    wrapper.unmount()
  })

  it('sur « Utilisateurs » : l\'onglet Utilisateurs est courant et « Contenu » n\'est pas actif', async () => {
    const wrapper = await mountLayout('/fr/admin/users')

    const usersTab = wrapper.get('a[aria-current="page"]')
    expect(usersTab.text()).toBe('Utilisateurs')
    expect(contentToggle(wrapper).classes()).not.toContain('btn-gradient')
    expect(contentToggle(wrapper).classes()).toContain('btn-outline-light')

    wrapper.unmount()
  })

  /**
   * Le téléchargement du CV complet est ici pour ROLE_SUPER, et non dans
   * l'en-tête (AppHeader) : c'est le seul palier dont la barre de droite
   * débordait entre 1200 et 1399 px (mesure de l'issue #88). Les autres
   * comptes de confiance gardent le bouton dans l'en-tête — ils n'ont pas
   * accès à cette page.
   */
  describe('téléchargement du CV (ROLE_SUPER)', () => {
    function downloadButton(wrapper: Awaited<ReturnType<typeof mountLayout>>) {
      return wrapper.findAll('nav button').find((button) => button.text().includes('Télécharger mon CV'))
    }

    it("propose « Télécharger mon CV » dans la navigation d'administration", async () => {
      const wrapper = await mountLayout('/fr/admin/technologies')

      expect(downloadButton(wrapper)).toBeDefined()
      expect(wrapper.find('[role="alert"]').exists()).toBe(false)

      wrapper.unmount()
    })

    it('le clic télécharge le fichier via le repository, sans message d\'erreur', async () => {
      vi.stubGlobal('URL', { ...URL, createObjectURL: vi.fn(() => 'blob:mock'), revokeObjectURL: vi.fn() })
      const cvRepository = createStubCvRepository()
      const wrapper = await mountLayout('/fr/admin/technologies', cvRepository)

      await downloadButton(wrapper)?.trigger('click')
      await nextTick()

      expect(cvRepository.download).toHaveBeenCalledTimes(1)
      expect(wrapper.find('[role="alert"]').exists()).toBe(false)

      wrapper.unmount()
      vi.unstubAllGlobals()
    })

    it('affiche un message si le téléchargement échoue', async () => {
      const cvRepository = createStubCvRepository({ download: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
      const wrapper = await mountLayout('/fr/admin/technologies', cvRepository)

      await downloadButton(wrapper)?.trigger('click')
      await nextTick()

      expect(wrapper.get('[role="alert"]').text()).toBe('Le téléchargement du CV a échoué. Réessayez plus tard.')

      wrapper.unmount()
    })
  })

  it('le menu déroulant porte un aria-label distinct de la navigation', async () => {
    const wrapper = await mountLayout('/fr/admin/quality')

    await contentToggle(wrapper).trigger('click')

    expect(wrapper.get('nav').attributes('aria-label')).toBe('Navigation d\'administration')
    expect(wrapper.get('.dropdown-menu').attributes('aria-label')).toBe('Navigation du contenu')

    wrapper.unmount()
  })
})
