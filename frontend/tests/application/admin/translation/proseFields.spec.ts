import { describe, expect, it } from 'vitest'
import { reactive } from 'vue'
import { applyTranslationProposal, collectProseFields } from '../../../../src/application/admin/translation/proseFields'

const PROSE = ['title', 'body'] as const

describe('collectProseFields', () => {
  it('ne garde que les champs de prose non vides, tels quels (backticks compris)', () => {
    const form = reactive({ locale: 'fr', title: 'Panne', body: 'Voir `composer.lock`.', url: 'https://x', position: 2 })

    expect(collectProseFields(form, PROSE)).toEqual({ title: 'Panne', body: 'Voir `composer.lock`.' })
  })

  it('omet un champ blanc plutôt que d\'envoyer une chaîne vide (refusée par l\'API)', () => {
    const form = reactive({ title: 'Panne', body: '   ' })

    expect(collectProseFields(form, PROSE)).toEqual({ title: 'Panne' })
  })
})

describe('applyTranslationProposal', () => {
  it('remplace les champs de prose présents dans la proposition et laisse les autres intacts', () => {
    const form = reactive({ locale: 'fr', title: 'Panne', body: 'Corps.', url: 'https://x', position: 2 })

    applyTranslationProposal(form, PROSE, { sourceLocale: 'fr', targetLocale: 'en', fields: { title: 'Outage', body: 'Body.' } })

    expect(form).toMatchObject({ title: 'Outage', body: 'Body.', url: 'https://x', position: 2 })
  })

  it('ne touche pas à un champ de prose absent de la proposition', () => {
    const form = reactive({ title: 'Panne', body: '' })

    applyTranslationProposal(form, PROSE, { sourceLocale: 'fr', targetLocale: 'en', fields: { title: 'Outage' } })

    expect(form.body).toBe('')
  })
})
