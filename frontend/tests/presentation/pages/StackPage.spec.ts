import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import StackPage from '../../../src/presentation/pages/StackPage.vue'
import { WATCH_REPOSITORY } from '../../../src/application/watch/useWatch'
import type { WatchRepository } from '../../../src/domain/watch/repositories/WatchRepository'
import type { WatchContent, WatchedProduct } from '../../../src/domain/watch/entities/WatchContent'
import { createAppI18n } from '../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../support/axe'

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
    freshness: 'fresh',
  },
  vulnerabilities: {
    packagesScanned: 99,
    affectedCount: 0,
    checkedAt: '2026-09-07T10:47:58+00:00',
    freshness: 'fresh',
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
        ...SNAPSHOT,
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
        ...SNAPSHOT,
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
      createStubRepository({
        ...SNAPSHOT,
        releaseCycles: { products: [], refreshedAt: null, sourceStatus: null, freshness: 'never_refreshed' },
      }),
    )
    await flushPromises()

    expect(wrapper.text()).toContain('Aucun rafraîchissement')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  /**
   * « janvier 2027 » ne dit rien à qui ne fait pas le calcul de tête. Sans le
   * relatif, l'information la plus importante du tableau — une échéance à
   * quatre mois — a exactement la même apparence qu'une échéance à quatre ans.
   */
  describe('échéances', () => {
    beforeEach(() => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date('2026-09-07T12:00:00Z'))
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('affiche l’échéance en relatif à côté de la date', async () => {
      const wrapper = mountPage()
      await flushPromises()

      expect(wrapper.text()).toContain('décembre 2027')
      expect(wrapper.text()).toContain('dans 15 mois')
    })

    /**
     * Le signalement passe par le texte autant que par la couleur : « dans
     * 4 mois » se lit sans distinguer les teintes.
     */
    it('met en évidence une échéance proche', async () => {
      const wrapper = mountPage(
        createStubRepository({
          ...SNAPSHOT,
          releaseCycles: {
            ...SNAPSHOT.releaseCycles,
            products: [{ ...PHP, eolFrom: '2027-01-31' }],
          },
        }),
      )
      await flushPromises()

      expect(wrapper.find('tbody .text-warning').text()).toContain('dans 4 mois')
    })

    it('ne met pas en évidence une échéance lointaine', async () => {
      const wrapper = mountPage()
      await flushPromises()

      expect(wrapper.find('tbody .text-warning').exists()).toBe(false)
    })
  })

  /**
   * Trois états à ne jamais confondre. Le plus dangereux est le troisième :
   * afficher « aucune vulnérabilité » sans avoir cherché serait le mensonge le
   * plus confortable de cette page.
   */
  describe('vulnérabilités', () => {
    it('annonce un périmètre sain sans laisser croire à une absence d’analyse', async () => {
      const wrapper = mountPage()
      await flushPromises()

      expect(wrapper.text()).toContain('Aucune vulnérabilité connue sur les 99 paquets')
    })

    it('signale les vulnérabilités trouvées dans une région annoncée', async () => {
      const wrapper = mountPage(
        createStubRepository({
          ...SNAPSHOT,
          vulnerabilities: { packagesScanned: 99, affectedCount: 3, checkedAt: '2026-09-07T10:47:58+00:00', freshness: 'fresh' },
        }),
      )
      await flushPromises()

      expect(wrapper.find('[role="status"]').text()).toContain('vulnérabilités connues')
      expect(wrapper.find('.badge.text-bg-danger').text()).toBe('3')
    })

    /**
     * Le singulier n'est pas un détail de style : « 1 vulnérabilités connues »
     * décrédibiliserait toute la page.
     */
    it('accorde le message au singulier', async () => {
      const wrapper = mountPage(
        createStubRepository({
          ...SNAPSHOT,
          vulnerabilities: { packagesScanned: 99, affectedCount: 1, checkedAt: null, freshness: 'fresh' },
        }),
      )
      await flushPromises()

      expect(wrapper.find('[role="status"]').text()).toContain('vulnérabilité connue')
      expect(wrapper.find('[role="status"]').text()).not.toContain('vulnérabilités')
    })

    /**
     * Le cas qui justifie tout le dispositif : sans manifeste, aucune analyse
     * n'a eu lieu. La page doit le dire, et surtout ne pas afficher un zéro.
     */
    it('distingue « analyse non effectuée » d’un périmètre sain', async () => {
      const wrapper = mountPage(
        createStubRepository({
          ...SNAPSHOT,
          vulnerabilities: { packagesScanned: null, affectedCount: 0, checkedAt: null, freshness: 'fresh' },
        }),
      )
      await flushPromises()

      expect(wrapper.text()).toContain('Analyse non effectuée')
      expect(wrapper.text()).not.toContain('Aucune vulnérabilité connue')
    })

    /**
     * Expliquer pourquoi on ne montre qu'un chiffre vaut mieux que de laisser
     * croire à un oubli — et c'est le compromis lui-même qui a de la valeur.
     */
    it('explique pourquoi le détail n’est pas public', async () => {
      const wrapper = mountPage(
        createStubRepository({
          ...SNAPSHOT,
          vulnerabilities: { packagesScanned: 99, affectedCount: 2, checkedAt: null, freshness: 'fresh' },
        }),
      )
      await flushPromises()

      expect(wrapper.text()).toContain('administration')
    })
  })

  /**
   * Une donnée datée reste plus utile qu'une page vide — à condition de dire
   * son âge. C'est la différence entre servir un relevé de l'avant-veille et
   * laisser croire qu'il date de ce matin.
   */
  describe('fraîcheur', () => {
    it('avertit quand le relevé des cycles de vie est en retard', async () => {
      const wrapper = mountPage(
        createStubRepository({
          ...SNAPSHOT,
          releaseCycles: { ...SNAPSHOT.releaseCycles, freshness: 'stale' },
        }),
      )
      await flushPromises()

      const warnings = wrapper.findAll('[role="status"]').map((element) => element.text())

      expect(warnings.some((text) => text.includes('Relevé en retard'))).toBe(true)
      // Le tableau reste affiché : la donnée est datée, pas fausse.
      expect(wrapper.find('table').exists()).toBe(true)
    })

    /**
     * Une analyse qui date n'est pas fausse, elle est incomplète : une faille
     * publiée depuis n'y figure pas. Un décompte à zéro lu comme s'il datait de
     * ce matin serait trompeur.
     */
    it('avertit quand l’analyse de vulnérabilités est en retard', async () => {
      const wrapper = mountPage(
        createStubRepository({
          ...SNAPSHOT,
          vulnerabilities: { ...SNAPSHOT.vulnerabilities, freshness: 'stale' },
        }),
      )
      await flushPromises()

      const warnings = wrapper.findAll('[role="status"]').map((element) => element.text())

      expect(warnings.some((text) => text.includes('Analyse en retard'))).toBe(true)
    })

    it('n’avertit de rien quand tout est à jour', async () => {
      const wrapper = mountPage()
      await flushPromises()

      expect(wrapper.text()).not.toContain('en retard')
    })
  })

  /**
   * Les lecteurs d'écran naviguent de titre en titre : un niveau sauté leur
   * fait manquer une section entière. C'est invisible à l'œil, et rien dans le
   * lint ne l'attrape — la hiérarchie ne se voit qu'une fois le composant rendu.
   */
  it('ne saute aucun niveau de titre', async () => {
    const wrapper = mountPage()
    await flushPromises()

    const niveaux = wrapper
      .findAll('h1, h2, h3, h4, h5, h6')
      .map((titre) => Number(titre.element.tagName[1]))

    expect(niveaux[0]).toBe(1)

    for (const [index, niveau] of niveaux.entries()) {
      if (0 === index) continue

      expect(niveau).toBeLessThanOrEqual(niveaux[index - 1] + 1)
    }
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

  /**
   * Audit du DOM rendu, complémentaire du lint d'accessibilité : celui-ci ne
   * voit que le template, axe inspecte ce qui existe une fois rendu.
   *
   * Attention à ce qu'il ne dit PAS : jsdom ne calcule ni mise en page ni
   * couleur, donc le contraste n'est jamais vérifié ici (cf. tests/support/axe).
   */
  it("ne présente aucune violation d'accessibilité détectable", async () => {
    const wrapper = mountPage()
    await flushPromises()

    await expectNoAccessibilityViolation(wrapper)
  })
})
