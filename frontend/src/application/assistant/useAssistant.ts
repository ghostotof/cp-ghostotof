import {
  computed,
  inject,
  onScopeDispose,
  readonly,
  ref,
  shallowRef,
  type ComputedRef,
  type InjectionKey,
  type Ref,
} from 'vue'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import type { AssistantMessage } from '../../domain/assistant/entities/AssistantMessage'
import { AssistantError } from '../../domain/assistant/errors/AssistantError'
import type { AssistantRepository } from '../../domain/assistant/repositories/AssistantRepository'
import {
  MAX_QUESTION_LENGTH,
  buildConversationWindow,
  codePointLength,
} from '../../domain/assistant/services/conversationWindow'
import { markSessionExpired } from '../auth/useAuth'

export const ASSISTANT_REPOSITORY: InjectionKey<AssistantRepository> = Symbol('AssistantRepository')

export type AssistantState = 'idle' | 'streaming' | 'error'

export interface UseAssistantResult {
  messages: Readonly<Ref<readonly AssistantMessage[]>>
  state: Readonly<Ref<AssistantState>>
  error: Readonly<Ref<AssistantError | null>>
  /** Saisie en cours, liée au champ de la page. */
  draft: Ref<string>
  /** Longueur de la saisie en points de code (le backend compte ainsi, pas en UTF-16). */
  draftLength: ComputedRef<number>
  canSend: ComputedRef<boolean>
  send: (locale: Locale) => Promise<void>
  reset: () => void
}

/**
 * Machine d'états de la conversation avec l'assistant (idle → streaming → idle
 * | error). Rien n'est persisté : la conversation vit dans ce composable et
 * disparaît avec la page. La fenêtre envoyée au backend est calculée par le
 * domaine (buildConversationWindow), jamais l'historique brut.
 */
export function useAssistant(): UseAssistantResult {
  const repository = inject(ASSISTANT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AssistantRepository n'a pas été fourni. Vérifiez que app.provide(ASSISTANT_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const messages = ref<AssistantMessage[]>([])
  const state = ref<AssistantState>('idle')
  const error = shallowRef<AssistantError | null>(null)
  const draft = ref('')

  // Appel en cours : tout résultat venant d'un autre contrôleur (appel abandonné
  // par reset() ou par le démontage) est ignoré.
  let controller: AbortController | null = null

  const draftLength = computed(() => codePointLength(draft.value))
  const canSend = computed(
    () => 'streaming' !== state.value && '' !== draft.value.trim() && draftLength.value <= MAX_QUESTION_LENGTH,
  )

  const abortCurrent = (): void => {
    // Sans argument : le repository ne reconnaît l'abandon que par l'AbortError natif.
    controller?.abort()
    controller = null
  }

  const send = async (locale: Locale): Promise<void> => {
    if (!canSend.value) {
      return
    }

    const question = draft.value.trim()
    // Fenêtre calculée AVANT d'ajouter la question, sinon elle se verrait deux fois.
    const window = buildConversationWindow(messages.value, question)

    messages.value.push({ role: 'user', content: question, status: 'complete' })
    messages.value.push({ role: 'assistant', content: '', status: 'streaming' })
    const answerIndex = messages.value.length - 1
    draft.value = ''
    error.value = null
    state.value = 'streaming'

    const current = new AbortController()
    controller = current
    const isCurrent = (): boolean => controller === current && !current.signal.aborted

    try {
      await repository.answer(
        locale,
        window,
        (text) => {
          if (isCurrent()) {
            messages.value[answerIndex].content += text
          }
        },
        current.signal,
      )
      if (isCurrent()) {
        messages.value[answerIndex].status = 'complete'
        state.value = 'idle'
        controller = null
      }
    } catch (caught) {
      if (!isCurrent()) {
        return
      }
      const failure = caught instanceof AssistantError ? caught : new AssistantError('unknown')
      const answer = messages.value[answerIndex]
      if ('' !== answer.content.trim()) {
        answer.status = 'incomplete'
      } else {
        // Rien reçu : on retire la bulle vide, la question reste affichée et
        // revient dans la saisie (si la personne n'a pas déjà retapé autre chose).
        messages.value.splice(answerIndex, 1)
        if ('' === draft.value) {
          draft.value = question
        }
      }
      if ('unauthenticated' === failure.reason || 'forbidden' === failure.reason) {
        markSessionExpired()
      }
      error.value = failure
      state.value = 'error'
      controller = null
    }
  }

  const reset = (): void => {
    abortCurrent()
    messages.value = []
    error.value = null
    state.value = 'idle'
  }

  onScopeDispose(abortCurrent)

  return {
    messages: readonly(messages),
    state: readonly(state),
    error: readonly(error),
    draft,
    draftLength,
    canSend,
    send,
    reset,
  }
}
