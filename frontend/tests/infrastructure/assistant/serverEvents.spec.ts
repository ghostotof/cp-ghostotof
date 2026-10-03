import { describe, expect, it } from 'vitest'
import { ServerEventParser } from '../../../src/infrastructure/assistant/serverEvents'

describe('ServerEventParser', () => {
  it('rend un événement complet', () => {
    const parser = new ServerEventParser()

    expect(parser.push('event: delta\ndata: {"text":"a"}\n\n')).toEqual([{ type: 'delta', data: '{"text":"a"}' }])
  })

  it('rend deux événements arrivés dans le même morceau', () => {
    const parser = new ServerEventParser()

    expect(parser.push('event: delta\ndata: 1\n\nevent: done\ndata: 2\n\n')).toEqual([
      { type: 'delta', data: '1' },
      { type: 'done', data: '2' },
    ])
  })

  it('assemble un événement coupé sur trois morceaux, y compris au milieu de data', () => {
    const parser = new ServerEventParser()

    expect(parser.push('event: del')).toEqual([])
    expect(parser.push('ta\ndata: {"te')).toEqual([])
    expect(parser.push('xt":"x"}\n\n')).toEqual([{ type: 'delta', data: '{"text":"x"}' }])
  })

  it('accepte \\r\\n et \\r comme fins de ligne, même coupés entre deux morceaux', () => {
    const parser = new ServerEventParser()

    expect(parser.push('event: delta\r\ndata: a\r\n\r\n')).toEqual([{ type: 'delta', data: 'a' }])
    expect(parser.push('data: b\r')).toEqual([])
    expect(parser.push('\n\r\n')).toEqual([{ type: 'message', data: 'b' }])
    // Un \r isolé est une fin de ligne : deux d'affilée terminent l'événement.
    expect(parser.push('data: c\r\rdata: d\n\n')).toEqual([
      { type: 'message', data: 'c' },
      { type: 'message', data: 'd' },
    ])
  })

  it('joint plusieurs lignes data par un saut de ligne', () => {
    const parser = new ServerEventParser()

    expect(parser.push('data: l1\ndata: l2\n\n')).toEqual([{ type: 'message', data: 'l1\nl2' }])
  })

  it('retire une seule espace après les deux-points', () => {
    const parser = new ServerEventParser()

    expect(parser.push('data:  deux\n\n')).toEqual([{ type: 'message', data: ' deux' }])
    expect(parser.push('data:collé\n\n')).toEqual([{ type: 'message', data: 'collé' }])
  })

  it('ignore les commentaires, id et retry', () => {
    const parser = new ServerEventParser()

    expect(parser.push(': ping\nid: 7\nretry: 100\nevent: done\ndata: x\n\n')).toEqual([{ type: 'done', data: 'x' }])
  })

  it('ne rend pas un reste sans ligne vide finale', () => {
    const parser = new ServerEventParser()

    expect(parser.push('event: delta\ndata: x\n')).toEqual([])
  })
})
