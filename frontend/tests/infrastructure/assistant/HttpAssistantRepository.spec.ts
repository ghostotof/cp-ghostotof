import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAssistantRepository } from '../../../src/infrastructure/assistant/HttpAssistantRepository'
import { AssistantError } from '../../../src/domain/assistant/errors/AssistantError'
import type { ConversationTurn } from '../../../src/domain/assistant/entities/AssistantMessage'

const encoder = new TextEncoder()
const messages: ConversationTurn[] = [{ role: 'user', content: 'Bonjour' }]

/** Réponse 200 dont le corps arrive en morceaux d'octets donnés. */
function streamResponse(chunks: Uint8Array[], init: ResponseInit = { status: 200 }): Response {
  const body = new ReadableStream<Uint8Array>({
    start(controller) {
      for (const chunk of chunks) {
        controller.enqueue(chunk)
      }
      controller.close()
    },
  })

  return new Response(body, init)
}

function textChunks(...parts: string[]): Uint8Array[] {
  return parts.map((part) => encoder.encode(part))
}

function event(type: string, data: unknown): string {
  return `event: ${type}\ndata: ${JSON.stringify(data)}\n\n`
}

const DONE = event('done', { promptTokens: 1, completionTokens: 2, durationMs: 3 })

describe('HttpAssistantRepository', () => {
  const fetchMock = vi.fn()
  const repository = new HttpAssistantRepository('http://api.test')

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    document.cookie = 'XSRF-TOKEN=tok%20en; path=/'
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
  })

  async function collect(): Promise<string[]> {
    const deltas: string[] = []
    await repository.answer('fr', messages, (text) => deltas.push(text))

    return deltas
  }

  it('envoie la requête attendue', async () => {
    fetchMock.mockResolvedValue(streamResponse(textChunks(DONE)))
    const signal = new AbortController().signal

    await repository.answer('en', messages, () => {}, signal)

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('http://api.test/api/assistant/answers')
    expect(init.method).toBe('POST')
    expect(init.credentials).toBe('include')
    expect(init.signal).toBe(signal)
    expect(init.headers).toEqual({
      'Content-Type': 'application/json',
      Accept: 'text/event-stream',
      'X-XSRF-TOKEN': 'tok en',
    })
    expect(JSON.parse(init.body as string)).toEqual({
      locale: 'en',
      messages: [{ role: 'user', content: 'Bonjour' }],
    })
  })

  it("omet X-XSRF-TOKEN quand le cookie est absent", async () => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    fetchMock.mockResolvedValue(streamResponse(textChunks(DONE)))

    await collect()

    const init = fetchMock.mock.calls[0][1] as RequestInit
    expect(init.headers).not.toHaveProperty('X-XSRF-TOKEN')
  })

  it('livre trois delta dans l’ordre puis résout sur done', async () => {
    fetchMock.mockResolvedValue(
      streamResponse(textChunks(event('delta', { text: 'a' }) + event('delta', { text: 'b' }), event('delta', { text: 'c' }), DONE)),
    )

    await expect(collect()).resolves.toEqual(['a', 'b', 'c'])
  })

  it('rejette unavailable sur un événement error, après les delta déjà livrés', async () => {
    fetchMock.mockResolvedValue(
      streamResponse(textChunks(event('delta', { text: 'a' }), event('error', { reason: 'provider' }))),
    )
    const deltas: string[] = []

    const error = await repository.answer('fr', messages, (t) => deltas.push(t)).catch((e: unknown) => e)

    expect(error).toBeInstanceOf(AssistantError)
    expect((error as AssistantError).reason).toBe('unavailable')
    expect(deltas).toEqual(['a'])
  })

  it('ignore un événement de type inconnu', async () => {
    fetchMock.mockResolvedValue(streamResponse(textChunks(event('ping', {}), event('delta', { text: 'a' }), DONE)))

    await expect(collect()).resolves.toEqual(['a'])
  })

  it('rejette network quand le flux se ferme sans done ni error', async () => {
    fetchMock.mockResolvedValue(streamResponse(textChunks(event('delta', { text: 'a' }))))

    await expect(collect()).rejects.toMatchObject({ reason: 'network' })
  })

  it('rejette network quand la lecture du flux échoue', async () => {
    const body = new ReadableStream<Uint8Array>({
      pull(controller) {
        controller.error(new TypeError('network error'))
      },
    })
    fetchMock.mockResolvedValue(new Response(body, { status: 200 }))

    await expect(collect()).rejects.toMatchObject({ reason: 'network' })
  })

  it('rejette unknown sur un JSON illisible dans data', async () => {
    fetchMock.mockResolvedValue(streamResponse(textChunks('event: delta\ndata: {pas du json\n\n')))

    await expect(collect()).rejects.toMatchObject({ reason: 'unknown' })
  })

  it('rejette unknown sur un delta sans texte', async () => {
    fetchMock.mockResolvedValue(streamResponse(textChunks(event('delta', { autre: 1 }))))

    await expect(collect()).rejects.toMatchObject({ reason: 'unknown' })
  })

  it.each([
    [401, 'unauthenticated'],
    [403, 'forbidden'],
    [413, 'too-large'],
    [422, 'validation'],
    [429, 'rate-limited'],
    [503, 'unavailable'],
    [500, 'unknown'],
    [404, 'unknown'],
  ])('traduit le statut %i en %s', async (status, reason) => {
    fetchMock.mockResolvedValue(new Response('{}', { status }))

    await expect(collect()).rejects.toMatchObject({ reason })
  })

  it('lit Retry-After en secondes sur un 429', async () => {
    fetchMock.mockResolvedValue(new Response('{}', { status: 429, headers: { 'Retry-After': '120' } }))

    await expect(collect()).rejects.toMatchObject({ reason: 'rate-limited', retryAfterSeconds: 120 })
  })

  it('convertit une date HTTP de Retry-After en secondes depuis maintenant', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-02T10:00:00Z'))
    try {
      fetchMock.mockResolvedValue(
        new Response('{}', { status: 429, headers: { 'Retry-After': 'Fri, 02 Oct 2026 10:01:30 GMT' } }),
      )
      await expect(collect()).rejects.toMatchObject({ retryAfterSeconds: 90 })

      fetchMock.mockResolvedValue(
        new Response('{}', { status: 429, headers: { 'Retry-After': 'Fri, 02 Oct 2026 09:00:00 GMT' } }),
      )
      await expect(collect()).rejects.toMatchObject({ retryAfterSeconds: 0 })
    } finally {
      vi.useRealTimers()
    }
  })

  it('rend null sans Retry-After ou avec une valeur illisible', async () => {
    fetchMock.mockResolvedValue(new Response('{}', { status: 429 }))
    await expect(collect()).rejects.toMatchObject({ retryAfterSeconds: null })

    fetchMock.mockResolvedValue(new Response('{}', { status: 429, headers: { 'Retry-After': 'bientôt' } }))
    await expect(collect()).rejects.toMatchObject({ retryAfterSeconds: null })
  })

  it('rejette network quand fetch rejette un TypeError', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    await expect(collect()).rejects.toMatchObject({ reason: 'network' })
  })

  it("relance l'AbortError tel quel", async () => {
    const abort = new DOMException('Aborted', 'AbortError')
    fetchMock.mockRejectedValue(abort)

    const error = await collect().catch((e: unknown) => e)

    expect(error).toBe(abort)
    expect(error).not.toBeInstanceOf(AssistantError)
  })

  it("relance l'AbortError levé pendant la lecture du flux", async () => {
    const abort = new DOMException('Aborted', 'AbortError')
    const body = new ReadableStream<Uint8Array>({
      pull(controller) {
        controller.error(abort)
      },
    })
    fetchMock.mockResolvedValue(new Response(body, { status: 200 }))

    await expect(collect()).rejects.toBe(abort)
  })

  it('reconstitue un caractère multi-octets coupé entre deux morceaux', async () => {
    const bytes = encoder.encode(event('delta', { text: 'é😀' }) + DONE)
    const accent = bytes.indexOf(0xc3) // premier octet de « é »
    const emoji = bytes.indexOf(0xf0) // premier octet de l'émoji
    fetchMock.mockResolvedValue(
      streamResponse([bytes.slice(0, accent + 1), bytes.slice(accent + 1, emoji + 2), bytes.slice(emoji + 2)]),
    )

    await expect(collect()).resolves.toEqual(['é😀'])
  })
})
