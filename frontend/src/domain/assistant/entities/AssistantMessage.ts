export type AssistantRole = 'user' | 'assistant'

/**
 * `streaming` : réponse en cours de réception ; `incomplete` : le flux s'est
 * interrompu avant `done`, le texte affiché est partiel.
 */
export type AssistantMessageStatus = 'complete' | 'streaming' | 'incomplete'

/** Ce que la page affiche. */
export interface AssistantMessage {
  role: AssistantRole
  content: string
  status: AssistantMessageStatus
}

/** Ce qui part sur le fil : la forme attendue par `AnswerRequest` côté backend. */
export interface ConversationTurn {
  role: AssistantRole
  content: string
}
