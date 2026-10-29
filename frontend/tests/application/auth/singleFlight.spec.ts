import { describe, expect, it, vi } from 'vitest'
import { createSingleFlight } from '../../../src/application/auth/singleFlight'

/** Une tâche dont le test tient la réponse : elle reste en vol tant que `settle`/`fail` n'est pas appelé. */
function deferredTask(): { task: () => Promise<string>; settle: (value: string) => void; fail: (error: Error) => void } {
  let settle: (value: string) => void = () => undefined
  let fail: (error: Error) => void = () => undefined
  const pending = new Promise<string>((resolve, reject) => {
    settle = resolve
    fail = reject
  })

  return { task: vi.fn(() => pending), settle, fail }
}

describe('createSingleFlight (#748, R41, St4-4)', () => {
  it('partage la promesse en vol : le second appel ne relance pas la tâche', async () => {
    const flight = createSingleFlight<string>()
    const { task, settle } = deferredTask()
    const other = vi.fn(async () => 'autre')

    const first = flight.run(task)
    const second = flight.run(other)
    settle('ok')

    expect(second).toBe(first)
    await expect(first).resolves.toBe('ok')
    expect(task).toHaveBeenCalledTimes(1)
    expect(other).not.toHaveBeenCalled()
  })

  it('libère après une résolution : un nouvel appel relance la tâche', async () => {
    const flight = createSingleFlight<string>()
    const task = vi.fn(async () => 'ok')

    await flight.run(task)
    await flight.run(task)

    expect(task).toHaveBeenCalledTimes(2)
  })

  it('libère après un rejet : un nouvel appel relance la tâche', async () => {
    const flight = createSingleFlight<string>()
    const { task, fail } = deferredTask()
    const next = vi.fn(async () => 'ok')

    const failing = flight.run(task)
    fail(new Error('réseau'))
    await expect(failing).rejects.toThrow('réseau')
    await expect(flight.run(next)).resolves.toBe('ok')

    expect(next).toHaveBeenCalledTimes(1)
  })

  it('une tâche qui lève de façon synchrone : rejet, puis libérée', async () => {
    const flight = createSingleFlight<string>()
    const throwing = vi.fn((): Promise<string> => {
      throw new Error('réseau')
    })
    const next = vi.fn(async () => 'ok')

    await expect(flight.run(throwing)).rejects.toThrow('réseau')
    await expect(flight.run(throwing)).rejects.toThrow('réseau')
    await expect(flight.run(next)).resolves.toBe('ok')

    expect(throwing).toHaveBeenCalledTimes(2)
  })

  it("clear() oublie la promesse en vol : le test suivant ne reçoit pas celle d'un autre", async () => {
    const flight = createSingleFlight<string>()
    const { task } = deferredTask()
    const next = vi.fn(async () => 'ok')

    void flight.run(task)
    flight.clear()

    await expect(flight.run(next)).resolves.toBe('ok')
    expect(next).toHaveBeenCalledTimes(1)
  })
})
