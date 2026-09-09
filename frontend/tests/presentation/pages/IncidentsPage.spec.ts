import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import IncidentsPage from '../../../src/presentation/pages/IncidentsPage.vue'
import { INCIDENT_REPOSITORY } from '../../../src/application/incidents/useIncidents'
import type { IncidentRepository } from '../../../src/domain/incidents/repositories/IncidentRepository'
import type { Incident } from '../../../src/domain/incidents/entities/Incident'
import { createAppI18n } from '../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../support/axe'

const INCIDENT: Incident = {
  title: 'RabbitMQ en CrashLoopBackOff après un durcissement de sécurité',
  version: 'v0.5.0',
  occurredAt: '2026-09-03',
  impact: 'Formulaire de contact en erreur 500 pendant une quinzaine de minutes.',
  rootCause: 'Le fichier `.erlang.cookie` est devenu accessible au groupe.',
  resolution: 'Correction du mode du fichier, hotfix v0.5.1.',
  invariant: 'Un changement de securityContext sur un service à état demande un déploiement réel en préprod.',
}

function createStubRepository(overrides: Partial<IncidentRepository> = {}): IncidentRepository {
  return {
    list: vi.fn(async () => [INCIDENT]),
    ...overrides,
  }
}

function mountPage(repository: IncidentRepository = createStubRepository()) {
  return mount(IncidentsPage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [INCIDENT_REPOSITORY as symbol]: repository },
    },
  })
}

describe('IncidentsPage', () => {
  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', () => {
    expect(mountPage().find('h1').exists()).toBe(true)
  })

  it('affiche un message de chargement pendant la récupération', () => {
    expect(mountPage().text()).toContain('Chargement des incidents')
  })

  it('affiche la version, le titre et les trois volets du post-mortem', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('v0.5.0')
    expect(wrapper.text()).toContain(INCIDENT.title)
    expect(wrapper.text()).toContain(INCIDENT.impact)
    expect(wrapper.text()).toContain(INCIDENT.resolution)
  })

  describe('invariant', () => {
    it('est toujours rendu, dans son propre encadré', async () => {
      const wrapper = mountPage()
      await flushPromises()

      // C'est la raison d'être de la page : une panne racontée sans la règle
      // qui en sort n'est qu'un aveu. L'encadré doit exister pour chaque
      // entrée, pas seulement quand la rédaction y a pensé.
      const callout = wrapper.get('.incident__invariant')
      expect(callout.text()).toContain(INCIDENT.invariant)
    })

    it('apparaît après les autres volets', async () => {
      const wrapper = mountPage()
      await flushPromises()

      const html = wrapper.html()
      expect(html.indexOf('incident__invariant')).toBeGreaterThan(html.indexOf(INCIDENT.resolution))
    })
  })

  describe('date', () => {
    it('est localisée à l\'affichage, et porte la valeur ISO en attribut', async () => {
      const wrapper = mountPage()
      await flushPromises()

      const time = wrapper.get('time')
      // datetime reste la valeur machine ; le texte est lisible.
      expect(time.attributes('datetime')).toBe('2026-09-03')
      expect(time.text()).toContain('2026')
      expect(time.text()).not.toBe('2026-09-03')
    })

    it('retombe sur la valeur brute plutôt que d\'afficher « Invalid Date »', async () => {
      const repository = createStubRepository({
        list: vi.fn(async () => [{ ...INCIDENT, occurredAt: 'pas-une-date' }]),
      })
      const wrapper = mountPage(repository)
      await flushPromises()

      expect(wrapper.get('time').text()).toBe('pas-une-date')
      expect(wrapper.text()).not.toContain('Invalid Date')
    })
  })

  it('rend les passages entre accents graves sans les interpréter comme du HTML', async () => {
    const repository = createStubRepository({
      list: vi.fn(async () => [{ ...INCIDENT, rootCause: 'Le champ `fsGroup` <script>x</script> est en cause.' }]),
    })
    const wrapper = mountPage(repository)
    await flushPromises()

    expect(wrapper.get('code').text()).toBe('fsGroup')
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.text()).toContain('<script>x</script>')
  })

  it('affiche un message d\'erreur générique si la récupération échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = mountPage(repository)
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('article').exists()).toBe(false)
  })

  it('affiche un état vide explicite quand aucun incident n\'est publié', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => []) })
    const wrapper = mountPage(repository)
    await flushPromises()

    expect(wrapper.text()).toContain('Aucun incident publié')
    expect(wrapper.find('article').exists()).toBe(false)
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
