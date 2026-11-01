import type { TranslationProposal } from '../../src/domain/admin/translation/entities/TranslationProposal'

/**
 * La proposition qu'une demande de traduction remet à `accept` (#748, R191), ou `null` si elle ne l'a pas appelée (refus, échec,
 * réponse périmée). `request` reçoit le `accept` à passer au composable : le composable et son appel diffèrent d'un spec à l'autre
 * (`translate` d'`useAdminTranslation`, `requestProposal` d'`useTranslatedForm`), la façon de garder la proposition reçue est
 * écrite ici, une fois.
 */
export async function capturedProposal(
  request: (accept: (proposal: TranslationProposal) => void) => Promise<unknown>,
): Promise<TranslationProposal | null> {
  let received: TranslationProposal | null = null
  await request((proposal) => { received = proposal })

  return received
}
