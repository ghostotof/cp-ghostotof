import type { RouteLocationNormalized } from 'vue-router'
import { SUPPORTED_LOCALES } from '../../domain/portfolio/entities/Locale'
import { i18n } from '../i18n'

const SITE_NAME = 'CP-Ghostotof'

function upsertMeta(name: string, content: string): void {
  let tag = document.querySelector<HTMLMetaElement>(`meta[name="${name}"]`)
  if (!tag) {
    tag = document.createElement('meta')
    tag.setAttribute('name', name)
    document.head.appendChild(tag)
  }
  tag.setAttribute('content', content)
}

function upsertLink(rel: string, href: string, hreflang?: string): void {
  const selector = hreflang ? `link[rel="${rel}"][hreflang="${hreflang}"]` : `link[rel="${rel}"]`
  let tag = document.querySelector<HTMLLinkElement>(selector)
  if (!tag) {
    tag = document.createElement('link')
    tag.setAttribute('rel', rel)
    if (hreflang) {
      tag.setAttribute('hreflang', hreflang)
    }
    document.head.appendChild(tag)
  }
  tag.setAttribute('href', href)
}

/**
 * SPA sans SSR : ces balises ne sont donc visibles qu'aux crawlers qui exécutent le
 * JS (Googlebot le fait). On applique malgré tout les bonnes pratiques multilingues :
 * <title>/description par page et par langue, canonical, hreflang (dont x-default).
 * Appelé depuis un `router.afterEach`, donc après que la locale a déjà été synchronisée
 * par le `beforeEach` de `presentation/router/index.ts`.
 */
export function applySeoMeta(to: RouteLocationNormalized): void {
  const { titleKey, descriptionKey } = to.meta

  document.title = titleKey ? `${SITE_NAME} — ${i18n.global.t(titleKey)}` : SITE_NAME
  if (descriptionKey) {
    upsertMeta('description', i18n.global.t(descriptionKey))
  }

  // Une page 404 n'a pas d'URL canonique valide à indexer ; la page de login
  // est un outil d'accès, pas un contenu éditorial à indexer.
  const isNonIndexable = 'not-found' === to.name || 'login' === to.name
  upsertMeta('robots', isNonIndexable ? 'noindex, nofollow' : 'index, follow')

  const locale = to.params.locale
  if (typeof locale !== 'string') {
    return
  }

  const pathWithoutLocale = to.path.slice(`/${locale}`.length)
  const origin = window.location.origin

  upsertLink('canonical', `${origin}${to.path}`)
  for (const supportedLocale of SUPPORTED_LOCALES) {
    upsertLink('alternate', `${origin}/${supportedLocale}${pathWithoutLocale}`, supportedLocale)
  }
  upsertLink('alternate', `${origin}/${SUPPORTED_LOCALES[0]}${pathWithoutLocale}`, 'x-default')
}
