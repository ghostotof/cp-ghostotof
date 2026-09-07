import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import StackPage from '../../../src/presentation/pages/StackPage.vue'
import { WATCH_REPOSITORY } from '../../../src/application/watch/useWatch'
import type { WatchRepository } from '../../../src/domain/watch/repositories/WatchRepository'
import type { WatchContent, WatchedProduct } from '../../../src/domain/watch/entities/WatchContent'
import { createAppI18n } from '../../../src/presentation/i18n'

const PHP: WatchedProduct = {
  slug: 'php',
  label: 'PHP',
  version: '8.5.9',
  status: 'supported',
  cycle: '8.5',
  endOfActiveSupportFrom: '2027-12-31',
  eolFrom: '2029-12-31',
  latestVersion: '8.5.10',
  hasNewerPatch: true,
  documentationUrl: 'https://endoflife.date/php',
}

const SNAPSHOT: WatchContent = {
  releaseCycles: {
    products: [PHP],
    refreshedAt: '2026-09-07T10:47:58+00:00',
    sourceStatus: 'ok',
  },
}

function createStubRepository(snapshot: WatchContent = SNAPSHOT): WatchRepository {
  return { get: vi.fn(async () => snapshot) }
}

function mountPage(repository: WatchRepository = createStubRepository()) {
  return mount(StackPage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [WATCH_REPOSITORY as symbol]: repository },
    },
  })
}

describe('StackPage', () => {
  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', () => {
    expect(mountPage().find('h1').exists()).toBe(true)
  })

  it('affiche un message de chargement pendant la récupération', () => {
    expect(mountPage().text()).toContain('Chargement')
  })

  it('signale une erreur de chargement dans une région annoncée aux lecteurs d’écran', async () => {
    const wrapper = mountPage({ get: vi.fn(async () => Promise.reject(new Error('boom'))) })
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('affiche le produit, sa version installée et ses échéances', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('PHP')
    expect(wrapper.text()).toContain('8.5.9')
    expect(wrapper.text()).toContain('8.5.10')
  })

  /**
   * Le statut ne doit jamais reposer sur la seule couleur du badge : un
   * daltonien ou un lecteur d'écran doit obtenir la même information.
   */
  it('exprime le statut en toutes lettres, pas seulement par une couleur', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('tbody .badge').text()).toBe('Supporté')
  })

  /**
   * Les libellés sont cherchés dans les badges eux-mêmes, pas dans le texte de
   * la page : « Fin de vie » est aussi un en-tête de colonne, et une assertion
   * sur le texte global passerait même si aucun badge n'était rendu.
   */
  it('traduit chaque statut de support', async () => {
    const wrapper = mountPage(
      createStubRepository({
        releaseCycles: {
          ...SNAPSHOT.releaseCycles,
          products: [
            { ...PHP, slug: 'a', status: 'eol' },
            { ...PHP, slug: 'b', status: 'security_only' },
            { ...PHP, slug: 'c', status: 'unknown' },
          ],
        },
      }),
    )
    await flushPromises()

    const badges = wrapper.findAll('tbody .badge').map((badge) => badge.text())

    expect(badges).toContain('Fin de vie')
    expect(badges).toContain('Sécurité uniquement')
    expect(badges).toContain('Inconnu')
  })

  /**
   * Ici aussi le badge est visé plutôt que le texte de la page : « Dernier
   * correctif » est un en-tête de colonne, et une assertion sur le texte
   * global serait vraie quelle que soit la valeur de hasNewerPatch.
   */
  it('signale qu’un correctif plus récent est disponible', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('tbody .badge.text-bg-info').text()).toContain('correctif')
  })

  /**
   * La version publiée et le fait qu'une mise à jour soit en attente sont deux
   * informations distinctes : la seconde est la plus actionnable du tableau et
   * se lit mieux alignée dans sa propre colonne que noyée à côté d'un numéro.
   */
  it('sépare la version du dernier correctif de l’indicateur de mise à jour', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.findAll('thead th')).toHaveLength(7)

    const cells = wrapper.findAll('tbody tr:first-child td')
    const latest = cells[cells.length - 2]
    const update = cells[cells.length - 1]

    expect(latest.text()).toBe('8.5.10')
    expect(latest.find('.badge').exists()).toBe(false)
    expect(update.find('.badge').text()).toContain('correctif')
  })

  it('n’annonce pas de correctif quand l’installation est à jour', async () => {
    const wrapper = mountPage(
      createStubRepository({
        releaseCycles: {
          ...SNAPSHOT.releaseCycles,
          products: [{ ...PHP, hasNewerPatch: false, latestVersion: '8.5.9' }],
        },
      }),
    )
    await flushPromises()

    expect(wrapper.find('tbody .badge.text-bg-info').exists()).toBe(false)
  })

  /**
   * L'ingénierie rendue visible : sans l'âge de la donnée, le visiteur ne peut
   * pas juger de ce qu'il lit. C'est ce qui distingue cette page d'un tableau
   * décoratif.
   */
  it('indique la date des données affichées', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('time').exists()).toBe(true)
  })

  /**
   * Sur une installation neuve, aucun rafraîchissement n'a encore abouti. Ce
   * n'est pas une panne, et la page ne doit pas laisser croire que la stack est
   * en parfait état — elle doit dire qu'elle ne sait pas encore.
   */
  it('distingue « jamais rafraîchi » d’un état sain', async () => {
    const wrapper = mountPage(
      createStubRepository({ releaseCycles: { products: [], refreshedAt: null, sourceStatus: null } }),
    )
    await flushPromises()

    expect(wrapper.text()).toContain('Aucun rafraîchissement')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  /**
   * Le tableau est large : il doit défiler dans son propre conteneur, sinon
   * c'est la page entière qui défile horizontalement sur mobile.
   */
  it('confine le défilement horizontal du tableau', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('.table-responsive').exists()).toBe(true)
  })
})
