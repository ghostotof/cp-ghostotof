import type { Locale } from '../../portfolio/entities/Locale'
import type { ConversationTurn } from '../entities/AssistantMessage'

export interface AssistantRepository {
  /**
   * Pose la conversation à l'assistant et livre la réponse fragment par fragment.
   * Résout sur `done`. Rejette une AssistantError, ou l'AbortError du signal tel quel.
   */
  answer(
    locale: Locale,
    messages: readonly ConversationTurn[],
    onDelta: (text: string) => void,
    signal?: AbortSignal,
  ): Promise<void>
}
