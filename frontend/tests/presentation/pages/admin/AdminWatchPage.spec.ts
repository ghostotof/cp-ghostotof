import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, RouterView, type Router } from 'vue-router'
import { defineComponent, h } from 'vue'
import AdminWatchPage from '../../../../src/presentation/pages/admin/AdminWatchPage.vue'
import { ADMIN_WATCHED_PRODUCT_REPOSITORY } from '../../../../src/application/admin/watch/useAdminWatchedProducts'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminWatchedProductRepository } from '../../../../src/domain/admin/watch/repositories/AdminWatchedProductRepository'
import type { AdminWatchedProduct } from '../../../../src/domain/admin/watch/entities/AdminWatchedProduct'
import { AdminWatchedProductError } from '../../../../src/domain/admin/watch/errors/AdminWatchedProductError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'
import { ADMIN_VULNERABILITY_REPOSITORY } from '../../../../src/application/admin/watch/useAdminVulnerabilities'
import type { AdminVulnerabilityRepository } from '../../../../src/domain/admin/watch/repositories/AdminVulnerabilityRepository'
import type { AdminVulnerability } from '../../../../src/domain/admin/watch/entities/AdminVulnerability'
import { expectNoAccessibilityViolation } from '../../../support/axe'

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

/**
 * Trois produits, la clé d'ordre étant l'id (spec 0004 : Watch n'a ni locale
 * ni groupe de traduction — une version n'est pas une traduction). Le tableau
 * a donc une ligne par produit, sans « Version de » ni « Créer la version ».
 */
const POSTGRES_ID = '019968a0-0000-7000-8000-000000000001'
const PHP_ID = '019968a0-0000-7000-8000-000000000002'
const RABBITMQ_ID = '019968a0-0000-7000-8000-000000000003'

const POSTGRES: AdminWatchedProduct = {
  id: POSTGRES_ID, slug: 'postgresql', label: 'PostgreSQL', versionSource: 'manual', version: '18.4', position: 0,
}
const PHP: AdminWatchedProduct = {
  id: PHP_ID, slug: 'php', label: 'PHP', versionSource: 'runtime_php', version: null, position: 1,
}
const RABBITMQ: AdminWatchedProduct = {
  id: RABBITMQ_ID, slug: 'rabbitmq', label: 'RabbitMQ', versionSource: 'manual', version: '4.0', position: 2,
}

const ALL_PRODUCTS = [POSTGRES, PHP, RABBITMQ]

function createStubRepository(
  overrides: Partial<AdminWatchedProductRepository> = {},
  products: readonly AdminWatchedProduct[] = ALL_PRODUCTS,
): AdminWatchedProductRepository {
  return {
    list: vi.fn(async () => products),
    create: vi.fn(async () => POSTGRES),
    update: vi.fn(async () => POSTGRES),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
    ...overrides,
  }
}

const ELSEWHERE = defineComponent({ setup: () => () => h('p', 'ailleurs') })

/**
 * La page est montée **par le routeur** : `onBeforeRouteLeave` n'existe que
 * dans un composant rendu par une `RouterView`, et la garde de sortie (D6) est
 * précisément l'un des points à vérifier.
 */
async function mountPage(
  repository: AdminWatchedProductRepository = createStubRepository(),
  vulnerabilityRepository: AdminVulnerabilityRepository = createStubVulnerabilityRepository(),
  options: { attach?: boolean } = {},
): Promise<{ wrapper: VueWrapper; router: Router }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/watch', component: AdminWatchPage },
      { path: '/admin/ailleurs', component: ELSEWHERE },
    ],
  })
  await router.push('/admin/watch')
  await router.isReady()

  const Host = defineComponent({ setup: () => () => h(RouterView) })
  const wrapper = mount(Host, {
    attachTo: options.attach ? document.body : undefined,
    global: {
      plugins: [createAppI18n(), router],
      provide: {
        [ADMIN_WATCHED_PRODUCT_REPOSITORY as symbol]: repository,
        [ADMIN_VULNERABILITY_REPOSITORY as symbol]: vulnerabilityRepository,
      },
    },
  })
  await flushPromises()

  return { wrapper, router }
}

/** Les lignes du tableau des produits — le premier `tbody`, le second étant celui des vulnérabilités. */
function rows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return wrapper.findAll('tbody')[0].findAll('tr')
}

function rowContaining(wrapper: VueWrapper, text: string): DOMWrapper<Element> {
  const row = rows(wrapper).find((candidate) => candidate.text().includes(text))
  if (!row) throw new Error(`Ligne introuvable pour « ${text} ».`)
  return row
}

function buttonLabelled(scope: VueWrapper | DOMWrapper<Element>, label: string): DOMWrapper<HTMLButtonElement> {
  const button = scope.findAll('button').find((candidate) => candidate.text() === label)
  if (!button) throw new Error(`Bouton « ${label} » introuvable.`)
  return button as DOMWrapper<HTMLButtonElement>
}

/** Glisse une ligne sur une autre, comme le ferait la souris. */
async function dragRow(wrapper: VueWrapper, from: number, to: number): Promise<void> {
  const tableRows = rows(wrapper)
  await tableRows[from].trigger('dragstart')
  await tableRows[to].trigger('dragover')
  await tableRows[to].trigger('drop')
}

describe('AdminWatchPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('affiche une ligne par produit surveillé, dans l\'ordre du serveur', async () => {
    const { wrapper } = await mountPage()

    expect(rows(wrapper)).toHaveLength(3)
    expect(rows(wrapper).map((row) => row.text())).toEqual([
      expect.stringContaining('postgresql'),
      expect.stringContaining('php'),
      expect.stringContaining('rabbitmq'),
    ])
    expect(wrapper.text()).toContain('18.4')
  })

  it('n\'affiche plus aucune colonne ni champ de position', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('#admin-watch-position').exists()).toBe(false)
    expect(wrapper.findAll('thead')[0].text()).not.toContain('Position')
  })

  it('signale un échec de chargement', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const { wrapper } = await mountPage(repository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('crée un produit via le formulaire, sans position (D3)', async () => {
    const repository = createStubRepository()
    const { wrapper } = await mountPage(repository)

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
    })
  })

  it('glisse la première ligne sur la troisième puis enregistre l\'ordre des ids', async () => {
    const repository = createStubRepository()
    const { wrapper } = await mountPage(repository)

    await dragRow(wrapper, 0, 2)
    expect(wrapper.text()).toContain('Ordre · modifié, non enregistré')
    expect(rows(wrapper).map((row) => row.text())).toEqual([
      expect.stringContaining('php'),
      expect.stringContaining('rabbitmq'),
      expect.stringContaining('postgresql'),
    ])

    vi.mocked(repository.list).mockClear()
    await buttonLabelled(wrapper, "Enregistrer l'ordre").trigger('click')
    await flushPromises()

    expect(repository.reorder).toHaveBeenCalledWith([PHP_ID, RABBITMQ_ID, POSTGRES_ID])
    expect(repository.list).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('Ordre · à jour')
  })

  it('déplace une ligne au clavier, garde le focus sur sa poignée et annonce la position', async () => {
    const { wrapper } = await mountPage(createStubRepository(), createStubVulnerabilityRepository(), { attach: true })

    const handle = rows(wrapper)[0].get('button')
    handle.element.focus()
    await handle.trigger('keydown', { key: 'ArrowDown' })
    await flushPromises()

    expect(rows(wrapper)[1].text()).toContain('postgresql')
    // Issue #170 F3 : l'annonce vit dans la barre du tableau, pas dans la ligne déplacée.
    expect(rows(wrapper)[1].find('[role="status"]').exists()).toBe(false)
    expect(wrapper.get('[role="status"]').text()).toBe('Déplacé en position 2 sur 3')
    expect(document.activeElement).toBe(rows(wrapper)[1].get('button').element)

    wrapper.unmount()
  })

  it('verrouille toutes les mutations tant que l\'ordre est modifié, et les libère sur Annuler', async () => {
    const { wrapper } = await mountPage()

    await dragRow(wrapper, 0, 2)

    const hint = wrapper.get('#admin-order-locked-hint')
    expect(hint.text()).toBe("Enregistrez ou annulez l'ordre d'abord.")

    const edit = buttonLabelled(rowContaining(wrapper, 'postgresql'), 'Modifier')
    expect(edit.attributes('disabled')).toBeDefined()
    expect(edit.attributes('aria-describedby')).toBe('admin-order-locked-hint')
    expect(buttonLabelled(rowContaining(wrapper, 'postgresql'), 'Supprimer').attributes('disabled')).toBeDefined()
    expect(buttonLabelled(wrapper, 'Enregistrer').attributes('disabled')).toBeDefined()

    await buttonLabelled(wrapper, 'Annuler').trigger('click')

    expect(buttonLabelled(rowContaining(wrapper, 'postgresql'), 'Modifier').attributes('disabled')).toBeUndefined()
    expect(buttonLabelled(wrapper, 'Enregistrer').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('#admin-order-locked-hint').exists()).toBe(false)
    expect(rows(wrapper)[0].text()).toContain('postgresql')
    expect(wrapper.text()).toContain('Ordre · à jour')
  })

  it('recharge la liste et annonce un ordre obsolète quand le serveur refuse l\'ensemble envoyé', async () => {
    const repository = createStubRepository({
      reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
    })
    const { wrapper } = await mountPage(repository)

    await dragRow(wrapper, 0, 2)
    vi.mocked(repository.list).mockClear()
    await buttonLabelled(wrapper, "Enregistrer l'ordre").trigger('click')
    await flushPromises()

    expect(repository.list).toHaveBeenCalledOnce()
    const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
    expect(alerts.some((text) => text.includes('La liste a changé entre-temps'))).toBe(true)
    expect(wrapper.text()).toContain('Ordre · à jour')
  })

  /**
   * L'invariant du domaine traduit dans l'interface : le slug construit l'URL
   * interrogée chez le fournisseur, en changer reviendrait à suivre un autre
   * produit. Le champ disparaît donc en édition, plutôt que de proposer une
   * saisie que le serveur refuserait en 409.
   */
  it('ne propose pas de modifier le slug en édition', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('#admin-watch-slug').exists()).toBe(true)

    await buttonLabelled(rowContaining(wrapper, 'postgresql'), 'Modifier').trigger('click')

    expect(wrapper.find('#admin-watch-slug').exists()).toBe(false)
    expect(wrapper.get('h2').text()).toBe('Modifier le produit surveillé')
  })

  /**
   * Même logique pour la version : elle n'a de sens que saisie à la main. Pour
   * PHP et Symfony, elle est lue dans le processus (D2), et un champ vide
   * laisserait croire qu'on peut la renseigner.
   */
  it('masque le champ version pour une source runtime', async () => {
    const { wrapper } = await mountPage()

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
    const { wrapper } = await mountPage(repository)

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
    const { wrapper } = await mountPage(repository)

    await buttonLabelled(rowContaining(wrapper, 'postgresql'), 'Supprimer').trigger('click')
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
    const { wrapper } = await mountPage(repository)

    await wrapper.find('#admin-watch-slug').setValue('postgresql')
    await wrapper.find('#admin-watch-label').setValue('Doublon')
    await wrapper.find('#admin-watch-version').setValue('18.4')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('déjà surveillé')
  })

  it('demande confirmation avant de quitter la route avec un ordre modifié, et reste sur place si on refuse', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { wrapper, router } = await mountPage()

    await dragRow(wrapper, 0, 2)
    await router.push('/admin/ailleurs')
    await flushPromises()

    expect(confirmSpy).toHaveBeenCalledOnce()
    expect(router.currentRoute.value.path).toBe('/admin/watch')
  })

  it('quitte la route sans rien demander quand l\'ordre est à jour', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { router } = await mountPage()

    await router.push('/admin/ailleurs')
    await flushPromises()

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/admin/ailleurs')
  })

  it('ne présente aucune violation d\'accessibilité, tableau ordonné, poignée, barre d\'ordre et vulnérabilités rendus', async () => {
    const { wrapper } = await mountPage(createStubRepository(), createStubVulnerabilityRepository([VULNERABILITY]))

    await expectNoAccessibilityViolation(wrapper)
  })

  /**
   * C'est ici, et nulle part ailleurs, que le détail des failles est visible :
   * la page publique n'en montre que le nombre (décision D4).
   */
  describe('vulnérabilités', () => {
    it('affiche le détail que la page publique ne montre pas', async () => {
      const { wrapper } = await mountPage(createStubRepository(), createStubVulnerabilityRepository([VULNERABILITY]))

      expect(wrapper.text()).toContain('GHSA-h7vf-5wrv-9fhv')
      expect(wrapper.text()).toContain('CVE-2022-24894')
      expect(wrapper.text()).toContain('symfony/http-kernel')
      expect(wrapper.text()).toContain('MODERATE')
      expect(wrapper.text()).toContain('4.4.50')
    })

    it('renvoie vers la fiche publiée par la base', async () => {
      const { wrapper } = await mountPage(createStubRepository(), createStubVulnerabilityRepository([VULNERABILITY]))

      const link = wrapper.get(`a[href="https://osv.dev/vulnerability/${VULNERABILITY.id}"]`)

      expect(link.text()).toBe(VULNERABILITY.id)
    })

    /**
     * Une entrée dont l'enrichissement avait échoué reste listée, avec des
     * tirets : la retirer ferait diverger le tableau du décompte public.
     */
    it('liste une entrée dépourvue de détail', async () => {
      const bare = { ...VULNERABILITY, aliases: [], summary: null, severity: null, fixedIn: null }
      const { wrapper } = await mountPage(createStubRepository(), createStubVulnerabilityRepository([bare]))

      expect(wrapper.text()).toContain(VULNERABILITY.id)
      expect(wrapper.findAll('tbody')[1].text()).toContain('—')
    })

    it('annonce l’absence de vulnérabilité connue', async () => {
      const { wrapper } = await mountPage()

      expect(wrapper.text()).toContain('Aucune vulnérabilité connue')
    })

    it('signale un échec de chargement des vulnérabilités', async () => {
      const { wrapper } = await mountPage(createStubRepository(), {
        list: vi.fn(async () => Promise.reject(new Error('unavailable'))),
      })

      expect(wrapper.text()).toContain('chargement des vulnérabilités a échoué')
    })
  })
})
