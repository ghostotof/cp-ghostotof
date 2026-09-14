import { describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'
import { useOrderDraft } from '../../../../src/application/admin/shared/useOrderDraft'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

describe('useOrderDraft', () => {
  it("suit serverKeys tant que le brouillon n'est pas modifié", async () => {
    const serverKeys = ref<readonly string[]>(['a', 'b'])
    const { draft, isDirty } = useOrderDraft({
      serverKeys,
      reorder: vi.fn(async () => {}),
      reload: vi.fn(async () => {}),
    })

    expect(draft.value).toEqual(['a', 'b'])

    serverKeys.value = ['a', 'b', 'c']
    await nextTick()

    expect(draft.value).toEqual(['a', 'b', 'c'])
    expect(isDirty.value).toBe(false)
  })

  it('devient modifié après un déplacement, reset restaure', () => {
    const serverKeys = ref<readonly string[]>(['a', 'b', 'c'])
    const { draft, isDirty, move, reset } = useOrderDraft({
      serverKeys,
      reorder: vi.fn(async () => {}),
      reload: vi.fn(async () => {}),
    })

    move(0, 2)
    expect(draft.value).toEqual(['b', 'c', 'a'])
    expect(isDirty.value).toBe(true)

    reset()
    expect(draft.value).toEqual(['a', 'b', 'c'])
    expect(isDirty.value).toBe(false)
  })

  it("un déplacement modifié n'est plus écrasé par un changement de serverKeys", async () => {
    const serverKeys = ref<readonly string[]>(['a', 'b', 'c'])
    const { draft, move } = useOrderDraft({
      serverKeys,
      reorder: vi.fn(async () => {}),
      reload: vi.fn(async () => {}),
    })

    move(0, 2)
    serverKeys.value = ['x', 'y', 'z']
    await nextTick()

    expect(draft.value).toEqual(['b', 'c', 'a'])
  })

  it("save appelle reorder avec l'ordre du brouillon puis recharge", async () => {
    const serverKeys = ref<readonly string[]>(['a', 'b', 'c'])
    const reorder = vi.fn(async () => {})
    const reload = vi.fn(async () => {})
    const { draft, isDirty, move, save, isSaving } = useOrderDraft({ serverKeys, reorder, reload })

    move(0, 2)
    const pending = save()
    expect(isSaving.value).toBe(true)
    await pending

    expect(reorder).toHaveBeenCalledWith(['b', 'c', 'a'])
    expect(reload).toHaveBeenCalledTimes(1)
    expect(isDirty.value).toBe(false)
    expect(isSaving.value).toBe(false)
    expect(draft.value).toEqual(['b', 'c', 'a'])
  })

  it('une erreur obsolète recharge la liste, réinitialise le brouillon et expose la raison', async () => {
    const serverKeys = ref<readonly string[]>(['a', 'b', 'c'])
    const reorder = vi.fn(async () => {
      throw new AdminOrderError('stale-order', 'obsolète')
    })
    const reload = vi.fn(async () => {
      serverKeys.value = ['x', 'y']
    })
    const { draft, isDirty, errorReason, move, save } = useOrderDraft({ serverKeys, reorder, reload })

    move(0, 2)
    await save()

    expect(reload).toHaveBeenCalledTimes(1)
    expect(errorReason.value).toBe('stale-order')
    expect(isDirty.value).toBe(false)
    expect(draft.value).toEqual(['x', 'y'])
  })

  it("une erreur inconnue expose la raison sans abandonner le brouillon ni recharger", async () => {
    const serverKeys = ref<readonly string[]>(['a', 'b', 'c'])
    const reorder = vi.fn(async () => {
      throw new Error('boom')
    })
    const reload = vi.fn(async () => {})
    const { draft, isDirty, errorReason, move, save } = useOrderDraft({ serverKeys, reorder, reload })

    move(0, 2)
    await save()

    expect(reload).not.toHaveBeenCalled()
    expect(errorReason.value).toBe('unknown')
    expect(isDirty.value).toBe(true)
    expect(draft.value).toEqual(['b', 'c', 'a'])
  })

  it("reset efface aussi une raison d'erreur affichée", async () => {
    const serverKeys = ref<readonly string[]>(['a', 'b', 'c'])
    const reorder = vi.fn(async () => {
      throw new Error('boom')
    })
    const { errorReason, move, save, reset } = useOrderDraft({
      serverKeys,
      reorder,
      reload: vi.fn(async () => {}),
    })

    move(0, 2)
    await save()
    expect(errorReason.value).toBe('unknown')

    reset()
    expect(errorReason.value).toBeNull()
  })

  it('accepte une fonction comme source de clés', () => {
    const keys: readonly string[] = ['a', 'b']
    const { draft } = useOrderDraft({
      serverKeys: () => keys,
      reorder: vi.fn(async () => {}),
      reload: vi.fn(async () => {}),
    })

    expect(draft.value).toEqual(['a', 'b'])
  })
})
