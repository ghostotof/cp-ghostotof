<script setup lang="ts">
import { computed, nextTick, onMounted, useTemplateRef } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useMcpConnections, type McpRevocationOutcome } from '../../application/mcp/useMcpConnections'
import type { McpConnectionSummary } from '../../domain/mcp/entities/McpConnection'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { formatInstant } from '../format/instant'
import { SESSION_ENDED_QUERY, SESSION_ENDED_VALUE } from '../router/sessionEnded'
import { useFocusThenAnnounce } from '../ui/useFocusThenAnnounce'

/**
 * « Mes connexions MCP » (#650, spec 0007 tâche 11) : les assistants que la
 * personne a autorisés, leur état, et la révocation de ceux qui sont encore en
 * vigueur. La route est réservée au palier de confiance (`ROLE_TRUSTED` ou
 * `ROLE_SUPER`) ; le serveur reste l'autorité : cette page ne protège rien.
 *
 * Le serveur juge l'état (`active`, `expired`, `revoked`) et la cause : la page
 * ne recalcule aucune échéance — et n'en décide pas l'absence : une connexion
 * révoquée arrive sans échéance (`null`), c'est le serveur qui le dit. Une connexion échue mais que la purge n'a pas
 * encore marquée arrive `expired` : elle n'a pas de bouton, elle n'a plus rien
 * à couper.
 *
 * Une seule table plutôt qu'une table par état : l'ordre est celui du serveur,
 * la colonne « État » dit l'essentiel, et deux tableaux avec leurs titres
 * rallongeraient la navigation au clavier d'une liste qui tient en quelques
 * lignes. Le titre de page est donc le seul titre ; pas de saut de niveau.
 *
 * L'historique des connexions terminées est borné par le serveur ; quand il
 * l'a coupé (`terminatedTruncated`), une mention le dit sous la table — sans
 * nombre : la borne est au serveur, la page n'en recopie aucune. La mention
 * est un paragraphe ordinaire, pas la région `role="status"` : elle ne change
 * pas sous les yeux de la personne, il n'y a rien à annoncer.
 *
 * Révoquer demande une confirmation (`window.confirm`, convention du
 * backoffice) qui nomme l'assistant : l'action coupe un accès que la personne
 * devra autoriser de nouveau. L'issue est annoncée dans la seule région
 * `role="status"` de la page, un échec dans une alerte.
 *
 * Focus et annonces (#748) : quand un geste déplace le focus ET annonce une
 * issue, le focus part d'abord et l'annonce est écrite ensuite — le patron et ses
 * raisons sont dans `useFocusThenAnnounce`. Le focus ne part au titre que si le
 * bouton cliqué a disparu (révoquée, déjà terminée) ou si la session est finie ;
 * sur un échec il revient au bouton, qui existe encore et que la personne peut
 * réessayer. Une lecture relancée (« Réessayer ») efface l'issue déjà annoncée :
 * elle ne dit rien d'une révocation.
 */

const { t, locale: i18nLocale } = useI18n()
const { status, connections, terminatedTruncated, revokingId, lastOutcome, sessionExpired, load, revoke } = useMcpConnections()
const route = useRoute()

const currentLocale = computed(() => i18nLocale.value as Locale)

/**
 * Le lien de reconnexion quand le serveur a refusé la session : la connexion,
 * avec un retour à cette page (`redirect`, jugé par LoginPage : chemin interne
 * seulement) et l'annonce qu'elle s'ouvre parce que la session a pris fin.
 * Un lien plutôt qu'une redirection automatique : la personne lit d'abord
 * pourquoi, et rien ne la déplace pendant qu'elle lit.
 */
const signInAgainLocation = computed(() => ({
  name: 'login',
  params: { locale: currentLocale.value },
  query: { redirect: route.fullPath, [SESSION_ENDED_QUERY]: SESSION_ENDED_VALUE },
}))

/**
 * Correspondances explicites plutôt qu'une clé construite à la volée : une
 * `t('mcpConnections.causes.' + cause)` échapperait à la vérification d'usage
 * des clés, et une cause ajoutée par un backend plus récent afficherait une clé
 * brute. Une cause absente de cette table tombe sur le libellé générique.
 */
const CAUSE_LABEL_KEYS: Readonly<Record<string, string>> = {
  person: 'mcpConnections.causes.person',
  client: 'mcpConnections.causes.client',
  'refresh-token-reuse': 'mcpConnections.causes.refreshTokenReuse',
  'code-replay': 'mcpConnections.causes.codeReplay',
  superseded: 'mcpConnections.causes.superseded',
  expired: 'mcpConnections.causes.expired',
  'password-changed': 'mcpConnections.causes.passwordChanged',
  'trusted-tier-withdrawn': 'mcpConnections.causes.trustedTierWithdrawn',
}

function causeLabel(cause: string | null): string {
  const key = null === cause ? undefined : Object.hasOwn(CAUSE_LABEL_KEYS, cause) ? CAUSE_LABEL_KEYS[cause] : undefined

  return t(key ?? 'mcpConnections.causes.unknown')
}

function stateLabel(connection: McpConnectionSummary): string {
  return 'active' === connection.state ? t('mcpConnections.states.active') : 'expired' === connection.state ? t('mcpConnections.states.expired') : causeLabel(connection.revocationCause)
}

function stateBadgeClass(connection: McpConnectionSummary): string {
  return 'active' === connection.state ? 'text-bg-success' : 'text-bg-secondary'
}

/**
 * Que dire d'une connexion qui n'a pas de dernier usage ? `last_used_at` n'est
 * écrit qu'au rafraîchissement : « jamais utilisée » serait faux d'une connexion
 * échangée et utilisée dans sa première heure. Le serveur dit si le code a été
 * échangé — `false`, l'accord n'a jamais abouti ; `true`, le client a ses jetons
 * et n'a simplement pas encore été renouvelé (#650, R5a).
 */
function noLastUseLabel(connection: McpConnectionSummary): string {
  return connection.codeExchanged ? t('mcpConnections.notRenewedYet') : t('mcpConnections.neverCompleted')
}

function date(iso: string): string {
  return formatInstant(iso, currentLocale.value)
}

/**
 * L'id de la mention d'historique coupé, que le tableau référence par `aria-describedby`
 * (#748, constat 19) : seulement quand la mention est rendue, une référence vers un id
 * absent est une faute.
 */
const TRUNCATED_NOTE_ID = 'mcp-connections-truncated'

const isLoading = computed(() => 'loading' === status.value)
/** La liste reste affichée pendant qu'elle est relue après une révocation : elle ne clignote pas. */
const showTable = computed(() => 'error' !== status.value && connections.value.length > 0)
const showEmpty = computed(() => 'ready' === status.value && 0 === connections.value.length)

/** L'issue que la région d'état dit : copie différée de `lastOutcome`, vide tant que le focus n'a pas bougé. */
const { announced: announcedOutcome, focusThenAnnounce, cancel: cancelAnnouncement, isUnmounted } = useFocusThenAnnounce<McpRevocationOutcome>()

/** Le seul `role="status"` de la page : vide hors chargement, révocation et issue à annoncer. */
const statusMessage = computed<string>(() => {
  if (null !== revokingId.value) {
    return t('mcpConnections.revoking')
  }
  if (isLoading.value && 0 === connections.value.length) {
    return t('mcpConnections.loading')
  }
  // L'issue annoncée, pas `lastOutcome` : elle n'est écrite qu'après le déplacement du focus.
  switch (announcedOutcome.value) {
    case 'revoked':
      return t('mcpConnections.outcome.revoked')
    case 'already-gone':
      return t('mcpConnections.outcome.alreadyGone')
    default:
      return ''
  }
})

const heading = useTemplateRef<HTMLHeadingElement>('heading')
const retryButton = useTemplateRef<HTMLButtonElement>('retryButton')

async function onRevoke(connection: McpConnectionSummary, event: MouseEvent): Promise<void> {
  if (!window.confirm(t('mcpConnections.confirm', { client: connection.client.name, date: date(connection.grantedAt) }))) {
    return
  }

  // Le navigateur retire le focus d'un bouton qui devient `disabled` pendant l'appel :
  // on garde l'élément pour le lui rendre si la révocation échoue.
  const clicked = event.currentTarget instanceof HTMLElement ? event.currentTarget : null
  cancelAnnouncement()

  await revoke(connection.id)
  if (isUnmounted()) {
    return
  }
  await nextTick()

  const outcome = lastOutcome.value
  if ('revoked' === outcome || 'already-gone' === outcome) {
    // Le bouton cliqué vient de disparaître (la connexion n'est plus active) : le
    // focus retomberait sur <body>. On le pose sur le titre, d'où la lecture reprend,
    // puis l'issue est annoncée une fois le focus posé.
    focusThenAnnounce(heading.value, outcome)
  } else if (sessionExpired.value) {
    // La session est finie : l'alerte et son lien de reconnexion sont lus depuis le titre.
    heading.value?.focus()
  } else {
    // Échec : le bouton existe encore, la personne peut réessayer là où elle était.
    clicked?.focus()
  }
}

/**
 * « Réessayer » : le bouton disparaît pendant la relecture (l'alerte est remplacée
 * par l'état de chargement) et le focus tomberait sur <body>. Au succès il passe
 * au titre ; au nouvel échec, au nouveau bouton — un second échec ne change pas le
 * texte de l'alerte, qu'aucune annonce ne signalerait. Session finie : le titre.
 */
async function onRetry(): Promise<void> {
  // Une lecture relancée ne dit rien d'une révocation : l'issue déjà annoncée s'efface (CR10).
  cancelAnnouncement()
  await load()
  if (isUnmounted()) {
    return
  }
  await nextTick()

  if ('error' === status.value && !sessionExpired.value) {
    retryButton.value?.focus()
  } else {
    heading.value?.focus()
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="container-xl py-5 d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h1
        ref="heading"
        class="h4 mb-3"
        tabindex="-1"
      >
        {{ t('mcpConnections.title') }}
      </h1>
      <p class="text-body-secondary mb-0">
        {{ t('mcpConnections.intro') }}
      </p>
    </div>

    <p
      role="status"
      class="text-body-secondary small mb-0"
    >
      {{ statusMessage }}
    </p>

    <!--
      La session a pris fin : réessayer renverrait la même requête sans session.
      Le message et le lien de reconnexion remplacent l'erreur générique et son
      bouton, au chargement comme à la révocation.
    -->
    <p
      v-if="sessionExpired"
      role="alert"
      class="text-danger-emphasis mb-0 d-flex flex-wrap align-items-center gap-3"
    >
      {{ t('mcpConnections.errors.sessionEnded') }}
      <RouterLink :to="signInAgainLocation">
        {{ t('mcpConnections.errors.signInAgain') }}
      </RouterLink>
    </p>

    <p
      v-else-if="'error' === status"
      role="alert"
      class="text-danger-emphasis mb-0 d-flex flex-wrap align-items-center gap-3"
    >
      {{ t('mcpConnections.errors.load') }}
      <button
        ref="retryButton"
        type="button"
        class="btn btn-outline-light btn-sm"
        @click="onRetry"
      >
        {{ t('mcpConnections.errors.retry') }}
      </button>
    </p>

    <p
      v-if="'failed' === lastOutcome && !sessionExpired"
      role="alert"
      class="text-danger-emphasis mb-0"
    >
      {{ t('mcpConnections.errors.revoke') }}
    </p>

    <p
      v-if="showEmpty"
      class="mb-0"
    >
      {{ t('mcpConnections.empty') }}
      <RouterLink :to="`/${currentLocale}/connect-your-assistant`">
        {{ t('common.connectAssistantLink') }}
      </RouterLink>
    </p>

    <!--
      Le tableau est occupé pendant la relecture, jamais un ancêtre de la région d'état (#748).
      `position-relative` : les textes `visually-hidden` (position absolue) de l'en-tête « Actions »
      et de « Sans échéance » trouvent leur ancêtre positionné dans ce conteneur défilant, au lieu de
      se placer hors de lui et d'élargir la page à 320 px (#748, constat 5).
    -->
    <div
      v-if="showTable"
      class="table-responsive position-relative"
      :aria-busy="isLoading"
    >
      <table
        class="table table-dark table-hover align-middle mb-0"
        :aria-describedby="terminatedTruncated ? TRUNCATED_NOTE_ID : undefined"
      >
        <caption class="visually-hidden">
          {{ t('mcpConnections.caption') }}
        </caption>
        <thead>
          <tr>
            <th scope="col">
              {{ t('mcpConnections.columns.client') }}
            </th>
            <th scope="col">
              {{ t('mcpConnections.columns.grantedAt') }}
            </th>
            <th scope="col">
              {{ t('mcpConnections.columns.lastUsedAt') }}
            </th>
            <th scope="col">
              {{ t('mcpConnections.columns.expiresAt') }}
            </th>
            <th scope="col">
              {{ t('mcpConnections.columns.state') }}
            </th>
            <th scope="col">
              <span class="visually-hidden">{{ t('mcpConnections.columns.actions') }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="connection in connections"
            :key="connection.id"
          >
            <th scope="row">
              {{ connection.client.name }}
            </th>
            <td>
              <time :datetime="connection.grantedAt">{{ date(connection.grantedAt) }}</time>
            </td>
            <td>
              <time
                v-if="null !== connection.lastUsedAt"
                :datetime="connection.lastUsedAt"
              >{{ date(connection.lastUsedAt) }}</time>
              <span
                v-else
                class="text-body-secondary"
              >{{ noLastUseLabel(connection) }}</span>
            </td>
            <td>
              <time
                v-if="null !== connection.expiresAt"
                :datetime="connection.expiresAt"
              >{{ date(connection.expiresAt) }}</time>
              <!-- Révoquée : le serveur ne donne plus d'échéance ; la case vide est dite aux lecteurs d'écran. -->
              <template v-else>
                <span
                  aria-hidden="true"
                  class="text-body-secondary"
                >—</span>
                <span class="visually-hidden">{{ t('mcpConnections.noExpiry') }}</span>
              </template>
            </td>
            <td>
              <span
                class="badge fw-normal text-wrap text-start"
                :class="stateBadgeClass(connection)"
              >{{ stateLabel(connection) }}</span>
              <small
                v-if="'revoked' === connection.state && null !== connection.revokedAt"
                class="d-block text-body-secondary mt-1"
              >{{ t('mcpConnections.revokedAt', { date: date(connection.revokedAt) }) }}</small>
            </td>
            <td class="text-end">
              <button
                v-if="'active' === connection.state"
                type="button"
                class="btn btn-outline-danger btn-sm"
                :disabled="sessionExpired || null !== revokingId"
                :aria-label="t('mcpConnections.revokeAria', { client: connection.client.name, date: date(connection.grantedAt) })"
                @click="onRevoke(connection, $event)"
              >
                {{ t('mcpConnections.revoke') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p
      v-if="terminatedTruncated"
      :id="TRUNCATED_NOTE_ID"
      class="text-body-secondary small mb-0"
      data-testid="terminated-truncated"
    >
      {{ t('mcpConnections.terminatedTruncated') }}
    </p>
  </section>
</template>

<style scoped>
/*
 * Un conteneur sans hauteur imposée n'a jamais à défiler à la verticale. Bootstrap pose
 * `overflow-x: auto` ; l'autre axe passe alors de `visible` à `auto` (règle CSS) et une barre
 * parasite d'un pixel peut apparaître dès que le contenu déborde d'un sous-pixel (#748, constat 18).
 */
.table-responsive {
  overflow-y: hidden;
}
</style>
