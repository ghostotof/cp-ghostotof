import type { AssistantMessage, ConversationTurn } from '../entities/AssistantMessage'

/*
 * Bornes de `Conversation` / `ConversationMessage` côté backend (spec 0005, D6),
 * recopiées à l'identique. « Caractères » = points de code (`mb_strlen` en PHP),
 * donc jamais `string.length`, qui compte des unités UTF-16.
 */
/** Nombre maximal de messages : impair, car l'alternance commence et finit par `user`. */
export const MAX_WINDOW_MESSAGES = 11
/** Total maximal de la conversation envoyée. */
export const MAX_CONVERSATION_LENGTH = 16000
export const MAX_QUESTION_LENGTH = 1000
export const MAX_ANSWER_LENGTH = 4000

/** Longueur en points de code (un émoji hors BMP compte pour 1). */
export function codePointLength(text: string): number {
  return [...text].length
}

/** Tronque en points de code : ne laisse jamais une moitié de paire de substitution. */
export function truncateToCodePoints(text: string, max: number): string {
  const points = [...text]
  return points.length <= max ? text : points.slice(0, max).join('')
}

/** Un échange : la question de la personne et la réponse exploitable qui l'a suivie. */
interface Exchange {
  question: ConversationTurn
  answer: ConversationTurn
}

/**
 * Extrait les échanges de l'historique affiché : un message `user` immédiatement
 * suivi d'un message `assistant` non vide (`complete` ou `incomplete`, jamais
 * `streaming`). Toute question sans réponse exploitable (erreur avant le premier
 * fragment, flux en cours) est écartée, sinon deux `user` consécutifs casseraient
 * l'alternance et le backend répondrait 422.
 */
function extractExchanges(history: readonly AssistantMessage[]): Exchange[] {
  const exchanges: Exchange[] = []
  let i = 0
  while (i < history.length) {
    const question = history[i]
    const answer = history[i + 1]
    if (
      question.role === 'user' &&
      answer !== undefined &&
      answer.role === 'assistant' &&
      answer.status !== 'streaming' &&
      answer.content.trim() !== ''
    ) {
      exchanges.push({
        question: { role: 'user', content: question.content },
        answer: { role: 'assistant', content: truncateToCodePoints(answer.content, MAX_ANSWER_LENGTH) },
      })
      i += 2
    } else {
      i += 1
    }
  }
  return exchanges
}

/**
 * Historique affiché + nouvelle question → ce qui part au backend, toujours valide
 * pour `Conversation` : alternance stricte, premier et dernier message `user`,
 * nombre impair ≤ 11, réponses ≤ 4 000 et total ≤ 16 000 points de code.
 * Les échanges les plus anciens tombent en premier ; la question reste toujours.
 */
export function buildConversationWindow(
  history: readonly AssistantMessage[],
  question: string,
): ConversationTurn[] {
  const current: ConversationTurn = { role: 'user', content: question.trim() }
  const maxExchanges = (MAX_WINDOW_MESSAGES - 1) / 2
  const kept = extractExchanges(history).slice(-maxExchanges)

  const flatten = (): ConversationTurn[] => [...kept.flatMap((e) => [e.question, e.answer]), current]
  const total = (turns: readonly ConversationTurn[]): number =>
    turns.reduce((sum, turn) => sum + codePointLength(turn.content), 0)

  let turns = flatten()
  while (kept.length > 0 && total(turns) > MAX_CONVERSATION_LENGTH) {
    kept.shift()
    turns = flatten()
  }
  return turns
}
