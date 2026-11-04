import type { Locale } from '../../domain/portfolio/entities/Locale'

/** Ce qui peut suivre le segment de langue : la fin du chemin, un segment de plus, la query ou le fragment. */
const AFTER_LOCALE_SEGMENT = /^(?:$|[/?#])/

/**
 * Le chemin `path` avec la langue `to` à la place de son premier segment, si ce segment est exactement `from`
 * (`/fr/about` → `/en/about`, `/fr?x=1` → `/en?x=1`) ; `null` sinon : `/frogs` ne commence pas par la langue `fr`, et un
 * chemin préfixé par une autre langue n'a pas de préfixe `from` à changer.
 *
 * La query et le fragment qui suivent le segment sont gardés tels quels. Le chemin n'est pas jugé ici : une cible de
 * retour (`?redirect=`) passe d'abord par `internalRedirectTarget()`.
 */
export function swapLocalePrefix(path: string, from: Locale, to: Locale): string | null {
  const prefix = `/${from}`
  if (!path.startsWith(prefix)) {
    return null
  }

  const rest = path.slice(prefix.length)

  return AFTER_LOCALE_SEGMENT.test(rest) ? `/${to}${rest}` : null
}
