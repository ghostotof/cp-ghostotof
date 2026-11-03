import en from '../../src/infrastructure/i18n/locales/en.json'
import fr from '../../src/infrastructure/i18n/locales/fr.json'
import type { Locale } from '../../src/domain/portfolio/entities/Locale'

/**
 * Le menu du compte (`AccountMenu`, rendu aussi par `AppHeader`) : son bouton, ses entrées et celles du palier de base, **lus
 * du catalogue** et jamais recopiés. Les specs qui jugent le menu jugent les mêmes entrées, dans le même ordre ; un libellé
 * retouché dans `fr.json` ne doit pas les faire rougir. La justesse des mots eux-mêmes se juge là où le texte est le sujet du
 * test, non ici. Même principe que `adminButtonTexts.ts` (les libellés du backoffice).
 *
 * Les clés sont rangées par l'application dans `nav` (les liens) et `common` (les actions et le bouton).
 */
const CATALOGUES = { fr, en } as const satisfies Record<Locale, unknown>

export interface AccountMenuTexts {
  readonly assistant: string
  readonly downloadCv: string
  readonly administration: string
  readonly mcpConnections: string
  readonly logout: string
  readonly myAccount: string
  /** Le nom du bouton qui ouvre le menu sur mobile (`aria-label`), distinct de `myAccount`, son libellé sur bureau. */
  readonly accountMenu: string
  readonly endBaseAccess: string
  readonly login: string
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
    accountMenu: catalogue.common.accountMenu,
    endBaseAccess: catalogue.common.endBaseAccess,
    login: catalogue.common.login,
  }
}
