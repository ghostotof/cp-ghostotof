import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  COOKIE_NOTICE_STORAGE_KEY,
  useCookieNotice,
} from '../../../src/application/cookieNotice/useCookieNotice'

describe('useCookieNotice', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('shows the notice on a first visit', () => {
    expect(useCookieNotice().isVisible.value).toBe(true)
  })

  it('hides the notice and remembers it once dismissed', () => {
    const notice = useCookieNotice()

    notice.dismiss()

    expect(notice.isVisible.value).toBe(false)
    expect(localStorage.getItem(COOKIE_NOTICE_STORAGE_KEY)).toBe('1')
    expect(useCookieNotice().isVisible.value).toBe(false)
  })

  it('ignores an unexpected stored value rather than trusting it', () => {
    localStorage.setItem(COOKIE_NOTICE_STORAGE_KEY, 'true')

    expect(useCookieNotice().isVisible.value).toBe(true)
  })

  it('still shows the notice when the storage cannot be read', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('denied', 'SecurityError')
    })

    expect(useCookieNotice().isVisible.value).toBe(true)
  })

  it('still closes for the visit when the storage cannot be written', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('full', 'QuotaExceededError')
    })
    const notice = useCookieNotice()

    expect(() => notice.dismiss()).not.toThrow()
    expect(notice.isVisible.value).toBe(false)
  })
})
