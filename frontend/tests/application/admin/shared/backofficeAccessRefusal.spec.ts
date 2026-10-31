import { describe, expect, it } from 'vitest'
import {
  backofficeAccessRefused,
  clearBackofficeAccessRefusal,
  earlierSaveRefused,
  markBackofficeAccessRefused,
  readRefusalScope,
  settleBackofficeAccessRefusalsOnMount,
} from '../../../../src/application/admin/shared/backofficeAccessRefusal'
import type { ReadRefusalScope } from '../../../../src/domain/admin/shared/BackofficeSessionReaction'

/** Les comptes des écritures de ces tests : le module retient le nom, jamais plus (R76). */
const ACCOUNT_A = 'compte-a'
const ACCOUNT_B = 'compte-b'

/** Ce qu'une lecture envoyée maintenant relève à l'envoi : le périmètre et l'époque courants (R54). */
function readSentNow(): ReadRefusalScope {
  return readRefusalScope()
}

/**
 * Le compteur des refus réels et son périmètre (#748, R28, CR3-8) : une navigation (un effacement) avance le
 * périmètre, et un refus relevé sous un périmètre révolu ne compte pas. Une écriture (`{ kind: 'write' }`) n'est jamais périmée
 * (R38). Aucun `afterEach` ici : `tests/support/setup.ts` remet l'état à zéro avant chaque test, et les deux
 * derniers tests le prouvent.
 */
describe('backofficeAccessRefusal', () => {
  it('un refus du périmètre courant compte et le dit', () => {
    expect(markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)).toBe(true)
    expect(markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)).toBe(true)

    expect(backofficeAccessRefused.value).toBe(2)
  })

  it('un effacement avance le périmètre : le refus relevé avant ne compte plus, rien ne change', () => {
    const sentBefore = readSentNow()
    clearBackofficeAccessRefusal()

    expect(readRefusalScope().scope).not.toBe(sentBefore.scope)
    expect(markBackofficeAccessRefused(sentBefore, ACCOUNT_A)).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)
  })

  it('un effacement remet le compteur à zéro', () => {
    markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)

    clearBackofficeAccessRefusal()

    expect(backofficeAccessRefused.value).toBe(0)
  })

  it("au montage, une écriture comptée garde le compteur et se dit « enregistrement précédent » ; le périmètre ne bouge pas (R64)", () => {
    const sentFromThisPage = readSentNow()
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)

    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    expect(backofficeAccessRefused.value).toBe(1)
    expect(earlierSaveRefused.value).toBe(true)
    expect(readRefusalScope().scope).toBe(sentFromThisPage.scope)
    expect(markBackofficeAccessRefused(sentFromThisPage, ACCOUNT_A)).toBe(true)
  })

  it("au montage, un refus de lecture seul est oublié : compteur à zéro, rien à dire, périmètre inchangé (R64)", () => {
    const sentFromThisPage = readSentNow()
    markBackofficeAccessRefused(sentFromThisPage, ACCOUNT_A)

    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    expect(backofficeAccessRefused.value).toBe(0)
    expect(earlierSaveRefused.value).toBe(false)
    expect(readRefusalScope().scope).toBe(sentFromThisPage.scope)
  })

  it("au montage, l'écriture comptée du compte courant est dite", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)

    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    expect(earlierSaveRefused.value).toBe(true)
    expect(backofficeAccessRefused.value).toBe(1)
  })

  it("au montage, l'écriture comptée d'un autre compte remet tout à zéro", () => {
    const sentFromThisPage = readSentNow()
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)

    settleBackofficeAccessRefusalsOnMount(ACCOUNT_B)

    expect(earlierSaveRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)
    expect(readRefusalScope().scope).toBe(sentFromThisPage.scope)
    // L'écriture comptée est oubliée aussi : un montage suivant, même sous le compte A, n'a plus rien à dire.
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(false)
  })

  it("au montage, une écriture comptée sous aucun compte n'est dite qu'à « aucun compte » (null contre null, R76)", () => {
    markBackofficeAccessRefused({ kind: 'write' }, null)

    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(false)

    markBackofficeAccessRefused({ kind: 'write' }, null)
    settleBackofficeAccessRefusalsOnMount(null)
    expect(earlierSaveRefused.value).toBe(true)
  })

  it("chaque lecture partie avant le montage garde l'avis, quel que soit le moment de son refus (#748, R112)", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    // Une page à deux listes (Qualité, À propos) : deux lectures estampillées dans le `setup` de la page, avant le montage.
    const firstLanding = readSentNow()
    const secondLanding = readSentNow()
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(true)
    expect(backofficeAccessRefused.value).toBe(1)

    markBackofficeAccessRefused(firstLanding, ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(true)
    expect(backofficeAccessRefused.value).toBe(2)

    // Le temps passe : la seconde lecture est refusée longtemps après, l'avis tient encore.
    markBackofficeAccessRefused(secondLanding, ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(true)
    expect(backofficeAccessRefused.value).toBe(3)
  })

  it("une lecture partie après le montage rend le texte courant (#748, R112)", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    const landing = readSentNow()
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    const afterMount = readSentNow()

    markBackofficeAccessRefused(afterMount, ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(2)

    // Et l'avis ne revient pas avec une lecture d'avant le montage arrivée ensuite : il est déjà rendu.
    markBackofficeAccessRefused(landing, ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(false)
  })

  it("une lecture de la page d'arrivée qui ne répond pas ne garde pas l'avis pour les suivantes (#748, R112)", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    // Cette lecture-ci ne répond jamais : aucun refus ne lui est rendu.
    readSentNow()
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    const afterMount = readSentNow()

    markBackofficeAccessRefused(afterMount, ACCOUNT_A)

    expect(earlierSaveRefused.value).toBe(false)
  })

  it("le montage avance l'époque, pas le périmètre (#748, R112)", () => {
    const before = readRefusalScope()
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)

    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    const after = readRefusalScope()
    expect(after.scope).toBe(before.scope)
    expect(after.epoch).not.toBe(before.epoch)
  })

  it("après un effacement, une lecture d'avant le montage est périmée (#748, R112)", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    const landing = readSentNow()
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    clearBackofficeAccessRefusal()

    expect(markBackofficeAccessRefused(landing, ACCOUNT_A)).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)
  })

  it("une écriture comptée sous aucun compte se distingue de l'absence d'écriture", () => {
    // Aucune écriture comptée, aucun compte au montage : rien à dire, même si « aucun » égale « aucun ».
    markBackofficeAccessRefused(readSentNow(), null)
    settleBackofficeAccessRefusalsOnMount(null)
    expect(earlierSaveRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)

    // Une écriture comptée sous aucun compte, aucun compte au montage : l'avis est dit.
    markBackofficeAccessRefused({ kind: 'write' }, null)
    settleBackofficeAccessRefusalsOnMount(null)
    expect(earlierSaveRefused.value).toBe(true)
  })

  it("après le montage, une écriture refusée ramène le texte courant", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(true)

    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)

    expect(earlierSaveRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(2)
  })

  it("un effacement remet l'avis à faux et oublie l'écriture comptée (R64)", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(true)

    // Un effacement oublie aussi l'écriture comptée : le montage suivant n'a rien à dire.
    clearBackofficeAccessRefusal()
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)
  })

  it("un refus d'écriture (`{ kind: 'write' }`) compte toujours, même après une navigation", () => {
    clearBackofficeAccessRefusal()

    expect(markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)).toBe(true)
    expect(backofficeAccessRefused.value).toBe(1)
  })

  // Deux tests successifs : le premier laisse un refus compté et un périmètre avancé, le second les doit à zéro.
  it('remise à zéro (1/2) : un refus compté et un périmètre avancé', () => {
    markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)
    clearBackofficeAccessRefusal()
    markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    expect(backofficeAccessRefused.value).toBe(2)
    expect(earlierSaveRefused.value).toBe(true)
    expect(readRefusalScope().scope).toBeGreaterThan(0)
    expect(readRefusalScope().epoch).toBeGreaterThan(0)
  })

  it("remise à zéro (2/2) : le test précédent n'a rien laissé", () => {
    // Ni l'écriture comptée (drapeau du module) ni le texte « enregistrement précédent » ne survivent à la remise à zéro.
    // Le périmètre et l'époque se lisent avant le montage, qui avance l'époque.
    expect(readRefusalScope()).toEqual({ kind: 'read', scope: 0, epoch: 0 })
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    expect(backofficeAccessRefused.value).toBe(0)
    expect(earlierSaveRefused.value).toBe(false)
  })
})
