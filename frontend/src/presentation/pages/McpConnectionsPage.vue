<script setup lang="ts">
import { computed, nextTick, onMounted, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMcpConnections } from '../../application/mcp/useMcpConnections'
import type { McpConnectionSummary } from '../../domain/mcp/entities/McpConnection'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { formatInstant } from '../format/instant'

/**
 * « Mes connexions MCP » (#650, spec 0007 tâche 11) : les assistants que la
 * personne a autorisés, leur état, et la révocation de ceux qui sont encore en
 * vigueur. La route est réservée au palier de confiance (`ROLE_TRUSTED` ou
 * `ROLE_SUPER`) ; le serveur reste l'autorité : cette page ne protège rien.
 *
 * Le serveur juge l'état (`active`, `expired`, `revoked`) et la cause : la page
 * ne recalcule aucune échéance. Une connexion échue mais que la purge n'a pas
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
 */

const { t, locale: i18nLocale } = useI18n()
const { status, connections, terminatedTruncated, revokingId, lastOutcome, load, revoke } = useMcpConnections()

const currentLocale = computed(() => i18nLocale.value as Locale)

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

function date(iso: string): string {
  return formatInstant(iso, currentLocale.value)
}

const isLoading = computed(() => 'loading' === status.value)
/** La liste reste affichée pendant qu'elle est relue après une révocation : elle ne clignote pas. */
const showTable = computed(() => 'error' !== status.value && connections.value.length > 0)
const showEmpty = computed(() => 'ready' === status.value && 0 === connections.value.length)

/** Le seul `role="status"` de la page : vide hors chargement, révocation et issue à annoncer. */
const statusMessage = computed<string>(() => {
  if (null !== revokingId.value) {
    return t('mcpConnections.revoking')
  }
  if (isLoading.value && 0 === connections.value.length) {
    return t('mcpConnections.loading')
  }
  switch (lastOutcome.value) {
    case 'revoked':
      return t('mcpConnections.outcome.revoked')
    case 'already-gone':
      return t('mcpConnections.outcome.alreadyGone')
    default:
      return ''
  }
})

const heading = useTemplateRef<HTMLHeadingElement>('heading')

async function onRevoke(connection: McpConnectionSummary): Promise<void> {
  if (!window.confirm(t('mcpConnections.confirm', { client: connection.client.name }))) {
    return
  }

  await revoke(connection.id)
  // Le bouton cliqué vient de disparaître (la connexion n'est plus active) : le
  // focus retomberait sur <body>. On le pose sur le titre, d'où la lecture reprend.
  await nextTick()
  heading.value?.focus()
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section
    class="container-xl py-5 d-flex flex-column gap-4"
    :aria-busy="isLoading"
  >
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

    <p
      v-if="'error' === status"
      role="alert"
      class="text-danger mb-0 d-flex flex-wrap align-items-center gap-3"
    >
      {{ t('mcpConnections.errors.load') }}
      <button
        type="button"
        class="btn btn-outline-light btn-sm"
        @click="load"
      >
        {{ t('mcpConnections.errors.retry') }}
      </button>
    </p>

    <p
      v-if="'failed' === lastOutcome"
      role="alert"
      class="text-danger mb-0"
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

    <div
      v-if="showTable"
      class="table-responsive"
    >
      <table class="table table-dark table-hover align-middle mb-0">
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
              >{{ t('mcpConnections.neverUsed') }}</span>
            </td>
            <td>
              <time :datetime="connection.expiresAt">{{ date(connection.expiresAt) }}</time>
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
                :disabled="null !== revokingId"
                :aria-label="t('mcpConnections.revokeAria', { client: connection.client.name, date: date(connection.grantedAt) })"
                @click="onRevoke(connection)"
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
      class="text-body-secondary small mb-0"
      data-testid="terminated-truncated"
    >
      {{ t('mcpConnections.terminatedTruncated') }}
    </p>
  </section>
</template>
