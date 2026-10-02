/** Un événement Server-Sent Events complet : `event:` (défaut `message`) et `data:` joint. */
export interface ServerEvent {
  type: string
  data: string
}

/**
 * Parseur incrémental de Server-Sent Events, pur (aucun accès réseau ni DOM).
 *
 * `EventSource` ne sait pas faire de POST ni envoyer le header CSRF : le flux est lu
 * à la main avec `fetch`, et les morceaux arrivent coupés n'importe où. On met donc
 * en tampon et on ne rend un événement que lorsque sa ligne vide finale est arrivée.
 * `\r\n`, `\r` et `\n` sont des fins de ligne équivalentes (spécification SSE).
 */
export class ServerEventParser {
  private buffer = ''

  push(chunk: string): ServerEvent[] {
    this.buffer += chunk

    // Un `\r` en toute fin de tampon peut être la première moitié d'un `\r\n` :
    // on le garde pour le prochain morceau plutôt que de le compter deux fois.
    let pending = ''
    if (this.buffer.endsWith('\r')) {
      pending = '\r'
      this.buffer = this.buffer.slice(0, -1)
    }

    const normalized = this.buffer.replace(/\r\n?/g, '\n')
    const blocks = normalized.split('\n\n')
    // Le dernier bloc n'est pas terminé par une ligne vide : il reste en tampon.
    this.buffer = (blocks.pop() ?? '') + pending

    const events: ServerEvent[] = []
    for (const block of blocks) {
      const event = this.parseBlock(block)
      if (event !== null) {
        events.push(event)
      }
    }

    return events
  }

  private parseBlock(block: string): ServerEvent | null {
    let type = 'message'
    const data: string[] = []
    let hasData = false

    for (const line of block.split('\n')) {
      // Commentaire (`: ping` de maintien de connexion) ou ligne vide résiduelle.
      if (line === '' || line.startsWith(':')) {
        continue
      }

      const separator = line.indexOf(':')
      const field = separator === -1 ? line : line.slice(0, separator)
      let value = separator === -1 ? '' : line.slice(separator + 1)
      if (value.startsWith(' ')) {
        value = value.slice(1)
      }

      if (field === 'event') {
        type = value
      } else if (field === 'data') {
        data.push(value)
        hasData = true
      }
      // `id` et `retry` ne servent à rien ici : pas de reconnexion automatique (cf. #318).
    }

    return hasData ? { type, data: data.join('\n') } : null
  }
}
