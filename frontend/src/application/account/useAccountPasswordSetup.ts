import { inject, readonly, ref, type DeepReadonly, type InjectionKey, type Ref } from 'vue'
import type { AccountRepository } from '../../domain/account/repositories/AccountRepository'
import { PasswordSetupLinkError, type PasswordSetupLinkErrorReason } from '../../domain/account/errors/PasswordSetupLinkError'

export const ACCOUNT_REPOSITORY: InjectionKey<AccountRepository> = Symbol('AccountRepository')

/**
 * - `checking`   : validation du jeton en cours (au montage de la page) ;
 * - `ready`      : lien exploitable, formulaire affichable ;
 * - `submitting` : soumission du mot de passe en cours ;
 * - `done`       : mot de passe défini, on peut rediriger vers /login ;
 * - `incomplete` : lien sans jeton ou au jeton mal formé (422), état terminal, pas de formulaire ;
 * - `unusable`   : lien inutilisable (404), quel qu'en soit le motif — inconnu, expiré, déjà
 *                  utilisé… le backend ne le dit pas (issue #362) —, état terminal ;
 * - `error`      : échec non récupérable de la *validation* (rate-limited /
 *                  réseau) — le formulaire n'a jamais été montré.
 *
 * Un échec *récupérable* de la soumission (mot de passe faible, rate-limited)
 * ramène à `ready` en renseignant `errorReason` : le formulaire reste utilisable.
 */
export type PasswordSetupState = 'checking' | 'ready' | 'submitting' | 'done' | 'incomplete' | 'unusable' | 'error'

export interface UseAccountPasswordSetupResult {
  state: DeepReadonly<Ref<PasswordSetupState>>
  errorReason: DeepReadonly<Ref<PasswordSetupLinkErrorReason | null>>
  validate: (token: string) => Promise<void>
  submit: (token: string, password: string) => Promise<void>
}

export function useAccountPasswordSetup(): UseAccountPasswordSetupResult {
  const repository = inject(ACCOUNT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AccountRepository n'a pas été fourni. Vérifiez que app.provide(ACCOUNT_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const state = ref<PasswordSetupState>('checking')
  const errorReason = ref<PasswordSetupLinkErrorReason | null>(null)

  /** `incomplete` / `unusable` sont terminaux ; les autres motifs restent récupérables. */
  function isTerminal(reason: PasswordSetupLinkErrorReason): reason is 'incomplete' | 'unusable' {
    return 'incomplete' === reason || 'unusable' === reason
  }

  const validate = async (token: string): Promise<void> => {
    // Pas de jeton (lien tronqué, ou page rechargée après que l'URL a été
    // nettoyée) : il n'y a rien à vérifier. On conclut sans appeler le backend,
    // qui répondrait 422 en entamant pour rien le quota par IP.
    if ('' === token) {
      state.value = 'incomplete'
      errorReason.value = 'incomplete'
      return
    }

    state.value = 'checking'
    errorReason.value = null

    try {
      await repository.validateSetupToken(token)
      state.value = 'ready'
    } catch (error) {
      const reason = error instanceof PasswordSetupLinkError ? error.reason : 'unknown'
      state.value = isTerminal(reason) ? reason : 'error'
      errorReason.value = reason
    }
  }

  const submit = async (token: string, password: string): Promise<void> => {
    state.value = 'submitting'
    errorReason.value = null

    try {
      await repository.completePasswordSetup(token, password)
      state.value = 'done'
    } catch (error) {
      const reason = error instanceof PasswordSetupLinkError ? error.reason : 'unknown'
      // Récupérable (mot de passe faible, rate-limited, réseau) : on rouvre le
      // formulaire ; terminal (lien mort/consommé) : on ferme.
      state.value = isTerminal(reason) ? reason : 'ready'
      errorReason.value = reason
    }
  }

  return { state: readonly(state), errorReason: readonly(errorReason), validate, submit }
}
