import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AdminWatchPage from '../../../../src/presentation/pages/admin/AdminWatchPage.vue'
import { ADMIN_WATCHED_PRODUCT_REPOSITORY } from '../../../../src/application/admin/watch/useAdminWatchedProducts'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminWatchedProductRepository } from '../../../../src/domain/admin/watch/repositories/AdminWatchedProductRepository'
import type { AdminWatchedProduct } from '../../../../src/domain/admin/watch/entities/AdminWatchedProduct'
import { AdminWatchedProductError } from '../../../../src/domain/admin/watch/errors/AdminWatchedProductError'
import { ADMIN_VULNERABILITY_REPOSITORY } from '../../../../src/application/admin/watch/useAdminVulnerabilities'
import type { AdminVulnerabilityRepository } from '../../../../src/domain/admin/watch/repositories/AdminVulnerabilityRepository'
import type { AdminVulnerability } from '../../../../src/domain/admin/watch/entities/AdminVulnerability'

const VULNERABILITY: AdminVulnerability = {
  id: 'GHSA-h7vf-5wrv-9fhv',
  aliases: ['CVE-2022-24894'],
  summary: 'Symfony storing cookie headers in HttpCache',
  severity: 'MODERATE',
  packageEcosystem: 'Packagist',
  packageName: 'symfony/http-kernel',
  packageVersion: '4.0.0',
  fixedIn: '4.4.50',
}

function createStubVulnerabilityRepository(
  vulnerabilities: readonly AdminVulnerability[] = [],
): AdminVulnerabilityRepository {
  return { list: vi.fn(async () => vulnerabilities) }
}

const POSTGRES: AdminWatchedProduct = {
  id: 1,
  slug: 'postgresql',
  label: 'PostgreSQL',
  versionSource: 'manual',
  version: '18.4',
  position: 0,
}

function createStubRepository(
  overrides: Partial<AdminWatchedProductRepository> = {},
  products: readonly AdminWatchedProduct[] = [POSTGRES],
): AdminWatchedProductRepository {
  return {
    list: vi.fn(async () => products),
    create: vi.fn(async () => POSTGRES),
    update: vi.fn(async () => POSTGRES),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

async function mountPage(
  repository: AdminWatchedProductRepository = createStubRepository(),
  vulnerabilityRepository: AdminVulnerabilityRepository = createStubVulnerabilityRepository(),
) {
  const wrapper = mount(AdminWatchPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [ADMIN_WATCHED_PRODUCT_REPOSITORY as symbol]: repository,
        [ADMIN_VULNERABILITY_REPOSITORY as symbol]: vulnerabilityRepository,
      },
    },
  })
  await flushPromises()

  return wrapper
}

describe('AdminWatchPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('affiche les produits surveillés chargés', async () => {
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('postgresql')
    expect(wrapper.text()).toContain('18.4')
  })

  it('signale un échec de chargement', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = await mountPage(repository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('crée un produit via le formulaire', async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await wrapper.find('#admin-watch-slug').setValue('nginx')
    await wrapper.find('#admin-watch-label').setValue('nginx')
    await wrapper.find('#admin-watch-version').setValue('1.30.4')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(repository.create).toHaveBeenCalledWith({
      slug: 'nginx',
      label: 'nginx',
      versionSource: 'manual',
      version: '1.30.4',
      position: 0,
    })
  })

  /**
   * L'invariant du domaine traduit dans l'interface : le slug construit l'URL
   * interrogée chez le fournisseur, en changer reviendrait à suivre un autre
   * produit. Le champ disparaît donc en édition, plutôt que de proposer une
   * saisie que le serveur refuserait en 409.
   */
  it('ne propose pas de modifier le slug en édition', async () => {
    const wrapper = await mountPage()

    expect(wrapper.find('#admin-watch-slug').exists()).toBe(true)

    await wrapper.findAll('tbody button')[0].trigger('click')

    expect(wrapper.find('#admin-watch-slug').exists()).toBe(false)
    expect(wrapper.text()).toContain('postgresql')
  })

  /**
   * Même logique pour la version : elle n'a de sens que saisie à la main. Pour
   * PHP et Symfony, elle est lue dans le processus (D2), et un champ vide
   * laisserait croire qu'on peut la renseigner.
   */
  it('masque le champ version pour une source runtime', async () => {
    const wrapper = await mountPage()

    expect(wrapper.find('#admin-watch-version').exists()).toBe(true)

    await wrapper.find('#admin-watch-version-source').setValue('runtime_php')

    expect(wrapper.find('#admin-watch-version').exists()).toBe(false)
  })

  /**
   * Et surtout, une version saisie puis abandonnée au profit d'une source
   * runtime ne doit pas partir au serveur : il la refuserait, à juste titre.
   */
  it('n’envoie aucune version pour une source runtime', async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await wrapper.find('#admin-watch-slug').setValue('php')
    await wrapper.find('#admin-watch-label').setValue('PHP')
    await wrapper.find('#admin-watch-version').setValue('8.5.9')
    await wrapper.find('#admin-watch-version-source').setValue('runtime_php')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(repository.create).toHaveBeenCalledWith(
      expect.objectContaining({ versionSource: 'runtime_php', version: null }),
    )
  })

  it('demande confirmation avant de retirer un produit', async () => {
    const repository = createStubRepository()
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = await mountPage(repository)

    await wrapper.findAll('tbody button')[1].trigger('click')
    await flushPromises()

    expect(confirmSpy).toHaveBeenCalled()
    expect(repository.remove).not.toHaveBeenCalled()
  })

  /**
   * Le 409 a son propre message : « déjà surveillé » et « identifiant non
   * modifiable » sont des refus que l'auteur corrige lui-même, pas des erreurs
   * de saisie ordinaires.
   */
  it('affiche un message dédié en cas de conflit', async () => {
    const repository = createStubRepository({
      create: vi.fn(async () => Promise.reject(new AdminWatchedProductError('conflict', 'already watched'))),
    })
    const wrapper = await mountPage(repository)

    await wrapper.find('#admin-watch-slug').setValue('postgresql')
    await wrapper.find('#admin-watch-label').setValue('Doublon')
    await wrapper.find('#admin-watch-version').setValue('18.4')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('déjà surveillé')
  })

  /**
   * C'est ici, et nulle part ailleurs, que le détail des failles est visible :
   * la page publique n'en montre que le nombre (décision D4).
   */
  describe('vulnérabilités', () => {
    it('affiche le détail que la page publique ne montre pas', async () => {
      const wrapper = await mountPage(createStubRepository(), createStubVulnerabilityRepository([VULNERABILITY]))

      expect(wrapper.text()).toContain('GHSA-h7vf-5wrv-9fhv')
      expect(wrapper.text()).toContain('CVE-2022-24894')
      expect(wrapper.text()).toContain('symfony/http-kernel')
      expect(wrapper.text()).toContain('MODERATE')
      expect(wrapper.text()).toContain('4.4.50')
    })

    it('renvoie vers la fiche publiée par la base', async () => {
      const wrapper = await mountPage(createStubRepository(), createStubVulnerabilityRepository([VULNERABILITY]))

      const link = wrapper.get(`a[href="https://osv.dev/vulnerability/${VULNERABILITY.id}"]`)

      expect(link.text()).toBe(VULNERABILITY.id)
    })

    /**
     * Une entrée dont l'enrichissement avait échoué reste listée, avec des
     * tirets : la retirer ferait diverger le tableau du décompte public.
     */
    it('liste une entrée dépourvue de détail', async () => {
      const bare = { ...VULNERABILITY, aliases: [], summary: null, severity: null, fixedIn: null }
      const wrapper = await mountPage(createStubRepository(), createStubVulnerabilityRepository([bare]))

      expect(wrapper.text()).toContain(VULNERABILITY.id)
      expect(wrapper.findAll('tbody')[1].text()).toContain('—')
    })

    it('annonce l’absence de vulnérabilité connue', async () => {
      const wrapper = await mountPage()

      expect(wrapper.text()).toContain('Aucune vulnérabilité connue')
    })

    it('signale un échec de chargement des vulnérabilités', async () => {
      const wrapper = await mountPage(createStubRepository(), {
        list: vi.fn(async () => Promise.reject(new Error('unavailable'))),
      })

      expect(wrapper.text()).toContain('chargement des vulnérabilités a échoué')
    })
  })
})
