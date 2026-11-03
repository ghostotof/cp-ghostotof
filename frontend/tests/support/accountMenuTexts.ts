import en from '../../src/infrastructure/i18n/locales/en.json'
import fr from '../../src/infrastructure/i18n/locales/fr.json'
import type { Locale } from '../../src/domain/portfolio/entities/Locale'

/**
 * Les entrées du menu du compte (`AccountMenu`, rendu aussi par `AppHeader`) et son bouton, **lus du catalogue** et jamais
 * recopiés : les deux specs jugent les mêmes entrées, dans le même ordre, et un libellé retouché dans `fr.json` ne doit pas
 * faire rougir des dizaines de sites répartis sur deux fichiers. La justesse des mots eux-mêmes se juge là où le texte est le
 * sujet du test, non dans ces constantes. Même principe que `adminButtonTexts.ts` (les libellés du backoffice).
 *
 * Six clés de deux espaces de noms, car l'application les a rangées ainsi : `nav.assistant` et `nav.mcpConnections` (les liens),
 * `common.downloadCv`, `common.administration`, `common.logout` et `common.myAccount` (les actions et le bouton).
 */
const CATALOGUES = { fr, en } as const satisfies Record<Locale, unknown>

export interface AccountMenuTexts {
  readonly assistant: string
  readonly downloadCv: string
  readonly administration: string
  readonly mcpConnections: string
  readonly logout: string
  readonly myAccount: string
}

export function accountMenuTexts(locale: Locale): AccountMenuTexts {
  const catalogue = CATALOGUES[locale]
  return {
    assistant: catalogue.nav.assistant,
    downloadCv: catalogue.common.downloadCv,
    administration: catalogue.common.administration,
    mcpConnections: catalogue.nav.mcpConnections,
    logout: catalogue.common.logout,
    myAccount: catalogue.common.myAccount,
  }
}
