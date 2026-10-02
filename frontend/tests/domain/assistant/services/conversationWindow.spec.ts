import { describe, expect, it } from 'vitest'
import type {
  AssistantMessage,
  AssistantMessageStatus,
} from '../../../../src/domain/assistant/entities/AssistantMessage'
import {
  MAX_ANSWER_LENGTH,
  MAX_CONVERSATION_LENGTH,
  MAX_QUESTION_LENGTH,
  MAX_WINDOW_MESSAGES,
  buildConversationWindow,
  codePointLength,
  truncateToCodePoints,
} from '../../../../src/domain/assistant/services/conversationWindow'

const q = (content: string): AssistantMessage => ({ role: 'user', content, status: 'complete' })
const a = (
  content: string,
  status: AssistantMessageStatus = 'complete',
): AssistantMessage => ({ role: 'assistant', content, status })

describe('codePointLength / truncateToCodePoints', () => {
  it('compte un émoji pour un point de code', () => {
    expect(codePointLength('😀')).toBe(1)
    expect(codePointLength('abc')).toBe(3)
  })

  it('ne coupe jamais une paire de substitution', () => {
    const out = truncateToCodePoints('a'.repeat(3999) + '😀😀', 4000)
    expect(out.endsWith('😀')).toBe(true)
    expect(codePointLength(out)).toBe(4000)
    expect(/[\uD800-\uDBFF]$/.test(out)).toBe(false)
  })

  it('laisse intact un texte assez court', () => {
    expect(truncateToCodePoints('bonjour', 10)).toBe('bonjour')
  })
})

describe('buildConversationWindow', () => {
  it('historique vide : la question seule, trimée', () => {
    expect(buildConversationWindow([], '  Bonjour ?  ')).toEqual([
      { role: 'user', content: 'Bonjour ?' },
    ])
  })

  it('sept échanges : les cinq derniers plus la question', () => {
    const history: AssistantMessage[] = []
    for (let i = 1; i <= 7; i++) history.push(q(`q${i}`), a(`r${i}`))
    const out = buildConversationWindow(history, 'nouvelle')
    expect(out).toHaveLength(MAX_WINDOW_MESSAGES)
    expect(out[0]).toEqual({ role: 'user', content: 'q3' })
    expect(out[out.length - 1]).toEqual({ role: 'user', content: 'nouvelle' })
  })

  it('écarte une question orpheline au milieu', () => {
    const out = buildConversationWindow([q('q1'), a('r1'), q('orpheline'), q('q2'), a('r2')], 'fin')
    expect(out.map((m) => m.content)).toEqual(['q1', 'r1', 'q2', 'r2', 'fin'])
  })

  it('écarte une question finale sans réponse', () => {
    const out = buildConversationWindow([q('q1'), a('r1'), q('sans réponse')], 'fin')
    expect(out.map((m) => m.content)).toEqual(['q1', 'r1', 'fin'])
  })

  it('écarte l\'échange dont la réponse est vide ou en streaming', () => {
    const out = buildConversationWindow(
      [q('q1'), a('   '), q('q2'), a('partiel', 'streaming'), q('q3'), a('r3')],
      'fin',
    )
    expect(out.map((m) => m.content)).toEqual(['q3', 'r3', 'fin'])
  })

  it('garde une réponse incomplète non vide', () => {
    const out = buildConversationWindow([q('q1'), a('début', 'incomplete')], 'fin')
    expect(out.map((m) => m.content)).toEqual(['q1', 'début', 'fin'])
  })

  it('tronque une réponse de 4 500 caractères à 4 000', () => {
    const out = buildConversationWindow([q('q1'), a('x'.repeat(4500))], 'fin')
    expect(codePointLength(out[1].content)).toBe(MAX_ANSWER_LENGTH)
  })

  it('retire les échanges les plus anciens au-delà de 16 000, la question restant', () => {
    const history: AssistantMessage[] = []
    for (let i = 1; i <= 5; i++) history.push(q(`q${i}`), a('x'.repeat(4000)))
    const out = buildConversationWindow(history, 'fin')
    const total = out.reduce((n, m) => n + codePointLength(m.content), 0)
    expect(total).toBeLessThanOrEqual(MAX_CONVERSATION_LENGTH)
    expect(out[0].content).toBe('q3')
    expect(out).toHaveLength(7)
    expect(out[out.length - 1].content).toBe('fin')
  })
})

/** PRNG déterministe (mulberry32) : le test de propriété reste reproductible. */
function mulberry32(seed: number): () => number {
  let s = seed
  return () => {
    s = (s + 0x6d2b79f5) | 0
    let t = Math.imul(s ^ (s >>> 15), 1 | s)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

describe('buildConversationWindow : propriété', () => {
  it('respecte toutes les bornes de Conversation sur 200 historiques aléatoires', () => {
    const rand = mulberry32(20261002)
    const pick = <T>(xs: readonly T[]): T => xs[Math.floor(rand() * xs.length)]
    const text = (max: number): string => {
      const n = Math.floor(rand() * max)
      const chars = ['a', 'é', '😀', ' ', '\n']
      let s = ''
      for (let i = 0; i < n; i++) s += pick(chars)
      return s
    }
    for (let run = 0; run < 200; run++) {
      const history: AssistantMessage[] = []
      const len = Math.floor(rand() * 30)
      for (let i = 0; i < len; i++) {
        history.push(
          rand() < 0.5
            ? q(text(MAX_QUESTION_LENGTH))
            : a(text(6000), pick(['complete', 'incomplete', 'streaming'] as const)),
        )
      }
      const question = `  ${text(MAX_QUESTION_LENGTH - 2)}x  `
      const out = buildConversationWindow(history, question)

      expect(out.length % 2).toBe(1)
      expect(out.length).toBeLessThanOrEqual(MAX_WINDOW_MESSAGES)
      out.forEach((m, i) => {
        expect(m.role).toBe(i % 2 === 0 ? 'user' : 'assistant')
        if (m.role === 'assistant') {
          expect(codePointLength(m.content)).toBeLessThanOrEqual(MAX_ANSWER_LENGTH)
        }
      })
      const total = out.reduce((n, m) => n + codePointLength(m.content), 0)
      expect(total).toBeLessThanOrEqual(MAX_CONVERSATION_LENGTH)
      expect(out[out.length - 1].content).toBe(question.trim())
    }
  })
})
