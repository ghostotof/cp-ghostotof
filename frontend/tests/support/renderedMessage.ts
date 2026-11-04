import { createAppI18n } from '../../src/presentation/i18n'
import type { Locale } from '../../src/domain/portfolio/entities/Locale'
import type { CatalogKey } from './catalogKey'

/** Une instance par langue, créée au premier appel : le rendu d'un message ne reconstruit pas le catalogue entier. */
const instances: Partial<Record<Locale, ReturnType<typeof createAppI18n>>> = {}

function instanceFor(locale: Locale): ReturnType<typeof createAppI18n> {
  const existing = instances[locale]
  if (undefined !== existing) return existing

  const created = createAppI18n()
  created.global.locale.value = locale
  instances[locale] = created

  return created
}

/**
 * Un message du catalogue **à paramètres** (`{minutes}`, `{username}`, `{scanned}`, `{min}`) rendu par le vrai catalogue dans
 * la langue demandée, comme la page le rend, jamais recopié avec sa valeur : un libellé retouché dans `fr.json` ou `en.json`
 * ne fait pas rougir un spec qui juge la page, non la rédaction. `key` est un chemin du catalogue (`CatalogKey`) : une clé
 * inconnue casse la compilation. `plural` est le nombre qui choisit la forme d'un message à pluriel
 * (`« … {minutes} minute. | … {minutes} minutes. »`), comme le troisième argument de `t` dans la page ; sans lui, la
 * première forme est choisie. Un message sans paramètre se lit directement de `fr.json` / `en.json` dans la spec.
 */
export function renderedMessage(
  locale: Locale,
  key: CatalogKey,
  named: Record<string, string | number> = {},
  plural?: number,
): string {
  const { t } = instanceFor(locale).global

  return undefined === plural ? t(key, named) : t(key, named, plural)
}
