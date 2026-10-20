/**
 * @vitest-environment-options { "url": "https://site.example.test/fr/connect-your-assistant" }
 *
 * Origine distinctive, posée pour ce seul fichier : l'URL du serveur affichée
 * doit en venir. Avec l'origine par défaut de jsdom (`http://localhost:3000`),
 * une URL écrite en dur sur `localhost` passerait le test.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import ConnectAssistantPage from '../../../src/presentation/pages/ConnectAssistantPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { createAppI18n } from '../../../src/presentation/i18n'
import { router as appRouter } from '../../../src/presentation/router'
import { SUPPORTED_LOCALES, type Locale } from '../../../src/domain/portfolio/entities/Locale'
import { expectNoAccessibilityViolation } from '../../support/axe'
import { removeClipboard, stubClipboard, type WriteText } from '../../support/clipboard'

const SERVER_URL = 'https://site.example.test/api/mcp'
const StubPage = { template: '<div />' }

/**
 * Catalogue de la phase 1 (spec 0007, « Catalogue des outils ») : ce que la page
 * annonce doit être ce que le serveur sert, ni plus ni moins, dans les deux
 * langues. `get_nominative_corpus` est à part : listé, mais jamais servi sans
 * connexion MCP — la page le range dans « ce qui n'est jamais servi ».
 */
const ANONYMOUS_TIER_TOOLS = [
  'get_about',
  'get_quality',
  'get_technical_background',
  'list_contributions',
  'list_incidents',
  'get_tech_watch',
  'get_contact_info',
]
const BASE_TIER_TOOLS = ['get_anonymous_cv', 'list_case_studies']

async function mountPage(locale: Locale = 'fr') {
  const i18n = createAppI18n()
  i18n.global.locale.value = locale
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)/connect-your-assistant', name: 'connect-assistant', component: StubPage },
      { path: '/:locale(fr|en)/privacy-policy', name: 'privacy-policy', component: StubPage },
    ],
  })
  await router.push(`/${locale}/connect-your-assistant`)
  await router.isReady()

  return mount(ConnectAssistantPage, {
    global: {
      plugins: [router, i18n],
      provide: { [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository() },
    },
  })
}

describe('ConnectAssistantPage', () => {
  let writeText: WriteText

  beforeEach(() => {
    writeText = stubClipboard()
  })

  afterEach(() => {
    removeClipboard()
  })

  it('se rend en français, sous un titre de niveau page', async () => {
    const wrapper = await mountPage('fr')

    expect(wrapper.find('h1').text()).toBe('Brancher votre assistant')
  })

  it('se rend en anglais, sous un titre de niveau page', async () => {
    const wrapper = await mountPage('en')

    expect(wrapper.find('h1').text()).toBe('Connect your assistant')
  })

  it("affiche l'URL du serveur construite depuis l'origine de la page", async () => {
    const wrapper = await mountPage()

    expect(window.location.origin).toBe('https://site.example.test')
    expect(wrapper.find('[data-testid="mcp-server-url"] code').text()).toBe(SERVER_URL)
  })

  it.each(SUPPORTED_LOCALES)(
    'donne la configuration de chaque client courant (%s)',
    async (locale) => {
      const wrapper = await mountPage(locale)
      const clientNames = wrapper.findAll('[data-testid^="mcp-client-"] h3').map((heading) => heading.text())

      expect(clientNames).toEqual(['Claude Code', 'Claude Desktop & claude.ai', 'ChatGPT', 'Cursor', 'PhpStorm'])
    },
  )

  it("donne à Claude Code une commande portant l'URL du serveur", async () => {
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="mcp-client-claude-code"] pre code').text()).toBe(
      `claude mcp add --transport http cp-ghostotof ${SERVER_URL}`,
    )
  })

  it.each(['cursor', 'phpstorm'])('donne à %s un fragment JSON mcpServers portant l’URL du serveur', async (client) => {
    const wrapper = await mountPage()
    const json = JSON.parse(wrapper.find(`[data-testid="mcp-client-${client}"] pre code`).text())

    expect(json).toEqual({ mcpServers: { 'cp-ghostotof': { url: SERVER_URL } } })
  })

  it.each(['claude-apps', 'chatgpt'])('donne à %s l’URL du serveur à saisir', async (client) => {
    const wrapper = await mountPage()

    expect(wrapper.find(`[data-testid="mcp-client-${client}"] pre code`).text()).toBe(SERVER_URL)
  })

  it.each(SUPPORTED_LOCALES)('liste les outils servis par palier, sans connexion (%s)', async (locale) => {
    const wrapper = await mountPage(locale)
    const toolsOf = (tier: string) =>
      wrapper.findAll(`[data-testid="mcp-tier-${tier}"] li code`).map((code) => code.text())

    expect(toolsOf('anonymous')).toEqual(ANONYMOUS_TIER_TOOLS)
    expect(toolsOf('base')).toEqual(BASE_TIER_TOOLS)
  })

  it.each(SUPPORTED_LOCALES)('présente le prompt du serveur et ses arguments (%s)', async (locale) => {
    const prompt = (await mountPage(locale)).find('[data-testid="mcp-prompt"]')

    expect(prompt.findAll('code').map((code) => code.text())).toEqual(['present_for_role', 'role', 'locale', 'fr', 'en'])
    // La borne publiée par l'argument (PromptRole::MAX_CHARACTERS côté serveur).
    expect(prompt.text()).toContain('500')
  })

  it('dit ce que le serveur ne sert jamais : rien de nominatif sans connexion MCP, aucune écriture, aucun appel de modèle', async () => {
    const neverServed = (await mountPage('fr')).find('[data-testid="mcp-never-served"]').text()

    expect(neverServed).toContain('get_nominative_corpus')
    expect(neverServed).toContain('connexion MCP')
    expect(neverServed).toContain('écriture')
    expect(neverServed).toContain('modèle')
  })

  it('dit en anglais ce que le serveur ne sert jamais', async () => {
    const neverServed = (await mountPage('en')).find('[data-testid="mcp-never-served"]').text()

    expect(neverServed).toContain('get_nominative_corpus')
    expect(neverServed).toContain('MCP connection')
    expect(neverServed).toContain('write')
    expect(neverServed).toContain('model')
  })

  it('renvoie à la politique de confidentialité pour le journal des appels', async () => {
    const wrapper = await mountPage('en')

    expect(wrapper.find('a[href="/en/privacy-policy"]').exists()).toBe(true)
  })

  /**
   * Un bouton par bloc, nommé par ce qu'il copie : sans le complément, un
   * lecteur d'écran entendrait six « Copier » identiques.
   */
  it('nomme chaque bouton de copie différemment', async () => {
    const wrapper = await mountPage()
    const names = wrapper.findAll('button').map((button) => button.text())
    const guides = new StaticPortfolioContentRepository().getConnectAssistantContent('fr').clients.guides

    // L'URL du serveur, puis un bloc par client.
    expect(names.length).toBe(1 + guides.length)
    expect(new Set(names).size).toBe(names.length)
    expect(names.every((name) => name.startsWith('Copier '))).toBe(true)
  })

  it('copie la commande Claude Code et annonce la copie', async () => {
    const wrapper = await mountPage()
    const block = wrapper.find('[data-testid="mcp-client-claude-code"]')

    await block.find('button').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith(`claude mcp add --transport http cp-ghostotof ${SERVER_URL}`)
    expect(block.find('[role="status"]').text()).toBe('Copié dans le presse-papiers.')
  })

  it('ne saute aucun niveau de titre (h1 → h2 → h3)', async () => {
    const levels = (await mountPage()).findAll('h1, h2, h3, h4').map((heading) => Number(heading.element.tagName[1]))

    levels.forEach((level, index) => {
      if (index > 0) expect(level - levels[index - 1]).toBeLessThanOrEqual(1)
    })
    expect(levels[0]).toBe(1)
  })

  it.each(SUPPORTED_LOCALES)('ne présente aucune violation d’accessibilité détectable (%s)', async (locale) => {
    await expectNoAccessibilityViolation(await mountPage(locale))
  })
})

/**
 * Le routeur de l'application lui-même (comme adminGuard.spec.ts) : le titre, la
 * description et l'indexation sont posés par son `afterEach`, à partir des
 * `meta` de la route — c'est cette chaîne-là qu'il faut éprouver.
 */
describe('ConnectAssistantPage — SEO', () => {
  const description = () => document.querySelector('meta[name="description"]')?.getAttribute('content')
  const robots = () => document.querySelector('meta[name="robots"]')?.getAttribute('content')

  it('pose un titre et une description en français, page indexable', async () => {
    await appRouter.push('/fr/connect-your-assistant')

    expect(appRouter.currentRoute.value.name).toBe('connect-assistant')
    expect(document.title).toBe('CP-Ghostotof — Brancher votre assistant')
    expect(description()).toContain('MCP')
    expect(robots()).toBe('index, follow')
  })

  it('pose un titre et une description en anglais, page indexable', async () => {
    await appRouter.push('/en/connect-your-assistant')

    expect(document.title).toBe('CP-Ghostotof — Connect your assistant')
    expect(description()).toContain('MCP')
    expect(robots()).toBe('index, follow')
  })

  it('décrit la page autrement dans chaque langue', async () => {
    await appRouter.push('/fr/connect-your-assistant')
    const french = description()
    await appRouter.push('/en/connect-your-assistant')

    expect(description()).not.toBe(french)
  })
})
