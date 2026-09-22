import { ref, type Ref } from 'vue'

export const COOKIE_NOTICE_STORAGE_KEY = 'cookieNoticeDismissed'

const DISMISSED = '1'

/**
 * Bandeau d'information sur les cookies — **information, pas consentement**.
 *
 * Le site ne pose que des traceurs strictement nécessaires à un service
 * expressément demandé (cookies `BEARER`/`XSRF-TOKEN` à la connexion ou à
 * l'accès instantané, préférences en `localStorage`), exemptés de consentement
 * (art. 82 de la loi Informatique et Libertés, lignes directrices CNIL). Il n'y
 * a donc rien à accepter ni à refuser : proposer un refus qui ne peut pas être
 * honoré serait un faux choix. Ce composable ne porte qu'un état « déjà lu ».
 *
 * Ne rien conditionner à cet état : il ne vaut pas consentement. Le jour où un
 * traceur non exempté entre dans le site, c'est une vraie gestion du
 * consentement qu'il faut (refus aussi simple que l'acceptation, retrait à tout
 * moment), pas une extension de ce bandeau.
 *
 * La clé elle-même est un stockage purement fonctionnel, jamais transmise au
 * serveur (registre des traitements, §4).
 */
export function useCookieNotice(): { isVisible: Ref<boolean>; dismiss: () => void } {
  const isVisible = ref(!wasDismissed())

  function dismiss(): void {
    isVisible.value = false
    try {
      localStorage.setItem(COOKIE_NOTICE_STORAGE_KEY, DISMISSED)
    } catch {
      // Stockage indisponible (navigation privée, quota, données de site bloquées) :
      // le bandeau se ferme pour cette visite et reparaîtra à la suivante.
    }
  }

  return { isVisible, dismiss }
}

function wasDismissed(): boolean {
  try {
    return localStorage.getItem(COOKIE_NOTICE_STORAGE_KEY) === DISMISSED
  } catch {
    return false
  }
}
