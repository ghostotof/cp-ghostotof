import { createAppI18n } from '../../src/presentation/i18n'
import type { Locale } from '../../src/domain/portfolio/entities/Locale'

/**
 * Un message du catalogue **à paramètres** (`{minutes}`, `{username}`, `{scanned}`, `{min}`) rendu par le vrai catalogue dans
 * la langue demandée, comme la page le rend, jamais recopié avec sa valeur : un libellé retouché dans `fr.json` ou `en.json`
 * ne fait pas rougir un spec qui juge la page, non la rédaction. `plural` est le nombre qui choisit la forme d'un message à
 * pluriel (`« … {minutes} minute. | … {minutes} minutes. »`), comme le troisième argument de `t` dans la page ; sans lui, la
 * première forme est choisie. Les messages sans paramètre se lisent directement de `fr.json` / `en.json` dans la spec.
 */
export function renderedMessage(
  locale: Locale,
  key: string,
  named: Record<string, string | number> = {},
  plural?: number,
): string {
  const i18n = createAppI18n()
  i18n.global.locale.value = locale

  return plural === undefined ? i18n.global.t(key, named) : i18n.global.t(key, named, plural)
}
