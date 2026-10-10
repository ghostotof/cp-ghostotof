import { describe, expect, it } from 'vitest'
import {
  MAX_PASSWORD_BYTES,
  MIN_PASSWORD_LENGTH,
  passwordLengthViolation,
} from '../../../../src/domain/account/services/passwordLength'

describe('passwordLengthViolation — contrat de PlainPasswordLength côté backend (#410)', () => {
  it('recopie les bornes de CpgUser : 8 points de code au moins, 4 096 octets au plus', () => {
    expect(MIN_PASSWORD_LENGTH).toBe(8)
    expect(MAX_PASSWORD_BYTES).toBe(4096)
  })

  it('accepte un mot de passe de 8 caractères ASCII', () => {
    expect(passwordLengthViolation('abcdefgh')).toBeNull()
  })

  it('refuse 7 caractères', () => {
    expect(passwordLengthViolation('abcdefg')).toBe('too-short')
  })

  it('compte en points de code : quatre emojis (8 unités UTF-16) sont trop courts', () => {
    expect('🔑🔑🔑🔑'.length).toBe(8)
    expect(passwordLengthViolation('🔑🔑🔑🔑')).toBe('too-short')
  })

  it('compte en points de code, pas en graphèmes : huit emojis suffisent', () => {
    expect(passwordLengthViolation('🔑'.repeat(8))).toBeNull()
  })

  it('accepte exactement 4 096 octets', () => {
    expect(passwordLengthViolation('a'.repeat(4096))).toBeNull()
    // 2 048 « é » = 4 096 octets en UTF-8.
    expect(passwordLengthViolation('é'.repeat(2048))).toBeNull()
  })

  it('mesure le maximum en octets UTF-8 : 2 049 « é » (4 098 octets) sont trop longs', () => {
    // 2 049 unités UTF-16 et 2 049 points de code : seul le compte en octets
    // dépasse la borne du hasher (`strlen`).
    expect(passwordLengthViolation('é'.repeat(2049))).toBe('too-long')
  })

  it('refuse 4 097 caractères ASCII', () => {
    expect(passwordLengthViolation('a'.repeat(4097))).toBe('too-long')
  })
})
