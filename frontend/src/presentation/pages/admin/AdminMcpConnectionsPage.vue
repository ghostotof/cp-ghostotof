<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useAdminMcpConnections } from '../../../application/admin/mcp/useAdminMcpConnections'
import { useCurrentLocale } from '../../../application/shared/useCurrentLocale'
import type { AdminMcpClientRegistration } from '../../../domain/admin/mcp/entities/AdminMcpClient'
import type { AdminMcpConnectionState } from '../../../domain/admin/mcp/entities/AdminMcpConnection'
import { formatInstant } from '../../format/instant'
import { REVOCATION_CAUSE_LABEL_KEYS } from '../../mcp/revocationCauseLabelKeys'

/**
 * Les connexions MCP de tous les comptes (#652, spec 0007 T13), **en lecture seule** : la révocation d'une
 * connexion par l'administrateur est hors du périmètre de la spec ; la personne révoque les siennes depuis
 * « Mes connexions MCP », l'administrateur coupe toutes celles d'un client en le supprimant.
 *
 * La page garde l'ordre du serveur et ne recalcule ni état ni échéance : le serveur les a jugés (D-D).
 * Les connexions terminées (révoquées, accords abandonnés) sont bornées côté serveur aux 200 plus récentes ;
 * la page le dit en permanence, sans savoir si elle tronque (limite acceptée du plan, D-D).
 */
const { t } = useI18n()
const { connections, isLoading, hasError, isLoaded } = useAdminMcpConnections()
const currentLocale = useCurrentLocale()

/** Les libellés des deux modes, repris de la page des clients : un mode ajouté au domaine casse `vue-tsc` ici. */
const REGISTRATION_LABEL_KEYS: Record<AdminMcpClientRegistration, string> = {
  preregistered: 'admin.mcpClients.registrations.preregistered',
  cimd: 'admin.mcpClients.registrations.cimd',
}

/**
 * Les libellés des trois états, par des clés littérales (règle `no-missing-keys` du lint) ; le `Record` impose
 * un libellé à chaque état du domaine. L'état est du texte, pas un badge : `text-bg-secondary` n'atteint pas
 * le contraste AA sur ce fond (`.claude/rules/frontend-a11y.md`), et l'état se lit sans couleur.
 */
const STATE_LABEL_KEYS: Record<AdminMcpConnectionState, string> = {
  active: 'admin.mcpConnections.states.active',
  expired: 'admin.mcpConnections.states.expired',
  revoked: 'admin.mcpConnections.states.revoked',
}

/** Un instant du serveur, écrit dans le fuseau du site et la langue de l'interface. */
function date(iso: string): string {
  return formatInstant(iso, currentLocale.value)
}

/**
 * La cause d'une révocation, par la table partagée avec « Mes connexions MCP » (F4). Une cause qu'un backend
 * plus récent écrirait tombe sur le libellé générique : jamais la cause brute, jamais une clé construite.
 * `Object.hasOwn` écarte les clés héritées (`constructor`…) qu'un simple accès indexé trouverait.
 *
 * Une exception, `person` (R13) : la table partagée parle à la personne (« Révoquée par vous »), qui lit ses propres
 * connexions ; ici l'administrateur lit celles des autres comptes, la page nomme donc la personne. Les autres causes
 * se lisent pareil des deux côtés et restent sur la table partagée, sans copie.
 */
function causeLabel(cause: string): string {
  if ('person' === cause) {
    return t('admin.mcpConnections.causes.person')
  }

  const key = Object.hasOwn(REVOCATION_CAUSE_LABEL_KEYS, cause) ? REVOCATION_CAUSE_LABEL_KEYS[cause] : undefined

  return t(key ?? 'mcpConnections.causes.unknown')
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.mcpConnections.listTitle') }}
      </h2>
      <!-- Toujours affichée, liste lue ou non : la borne est une propriété de la page, pas du résultat (D-D). -->
      <p class="text-body-secondary mb-3">
        {{ t('admin.mcpConnections.terminatedBound') }}
      </p>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.mcpConnections.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger-emphasis mb-0"
        role="alert"
      >
        {{ t('admin.mcpConnections.loadError') }}
      </p>
      <!-- Non lue n'est pas vide (#748, R4) : « Aucune connexion » seulement une fois la liste lue. -->
      <p
        v-else-if="!isLoaded"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.notLoaded') }}
      </p>
      <p
        v-else-if="0 === connections.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.mcpConnections.empty') }}
      </p>
      <!-- `position-relative` : les textes `visually-hidden` (position absolue) des cellules trouvent leur ancêtre
           positionné dans ce conteneur défilant, au lieu d'élargir la page à 320 px (#748, comme « Mes connexions MCP »). -->
      <div
        v-else
        class="table-responsive position-relative"
      >
        <table class="table table-dark align-middle mb-0">
          <caption class="visually-hidden">
            {{ t('admin.mcpConnections.caption') }}
          </caption>
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.mcpConnections.columns.account') }}
              </th>
              <th scope="col">
                {{ t('admin.mcpConnections.columns.client') }}
              </th>
              <th scope="col">
                {{ t('admin.mcpConnections.columns.grantedAt') }}
              </th>
              <th scope="col">
                {{ t('admin.mcpConnections.columns.lastUsedAt') }}
              </th>
              <th scope="col">
                {{ t('admin.mcpConnections.columns.expiresAt') }}
              </th>
              <th scope="col">
                {{ t('admin.mcpConnections.columns.state') }}
              </th>
              <th scope="col">
                {{ t('admin.mcpConnections.columns.cause') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="connection in connections"
              :key="connection.id"
            >
              <th
                scope="row"
                class="fw-normal text-break"
              >
                {{ connection.account }}
              </th>
              <td>
                <span class="d-block">{{ connection.clientName }}</span>
                <span class="d-block small text-break text-body-secondary">{{ connection.clientId }}</span>
                <span class="d-block small text-body-secondary">{{ t(REGISTRATION_LABEL_KEYS[connection.clientRegistration]) }}</span>
              </td>
              <td>
                <time :datetime="connection.grantedAt">{{ date(connection.grantedAt) }}</time>
              </td>
              <td>
                <time
                  v-if="null !== connection.lastUsedAt"
                  :datetime="connection.lastUsedAt"
                >{{ date(connection.lastUsedAt) }}</time>
                <!-- Sans dernier usage, le code dit pourquoi : jamais échangé (accord abandonné), ou échangé sans
                     renouvellement encore (`lastUsedAt` ne s'écrit qu'au renouvellement). -->
                <span
                  v-else-if="connection.codeExchanged"
                  class="text-body-secondary"
                >{{ t('mcpConnections.notRenewedYet') }}</span>
                <span
                  v-else
                  class="text-body-secondary"
                >{{ t('mcpConnections.neverCompleted') }}</span>
              </td>
              <td>
                <!-- Une connexion révoquée a devancé son échéance (R3) : la révocation d'abord, l'échéance prévue
                     ensuite, chacune nommée, pour qu'aucune date ne se lise comme la fin réelle de l'autre. -->
                <template v-if="'revoked' === connection.state">
                  <span
                    v-if="null !== connection.revokedAt"
                    class="d-block"
                  ><span class="small text-body-secondary">{{ t('admin.mcpConnections.revokedOn') }}</span> <time :datetime="connection.revokedAt">{{ date(connection.revokedAt) }}</time></span>
                  <span class="d-block"><span class="small text-body-secondary">{{ t('admin.mcpConnections.plannedExpiry') }}</span> <time :datetime="connection.expiresAt">{{ date(connection.expiresAt) }}</time></span>
                </template>
                <time
                  v-else
                  :datetime="connection.expiresAt"
                >{{ date(connection.expiresAt) }}</time>
              </td>
              <td>
                {{ t(STATE_LABEL_KEYS[connection.state]) }}
              </td>
              <td>
                <template v-if="null === connection.revocationCause">
                  <span
                    aria-hidden="true"
                    class="text-body-secondary"
                  >—</span>
                  <span class="visually-hidden">{{ t('admin.mcpConnections.noCause') }}</span>
                </template>
                <template v-else>
                  {{ causeLabel(connection.revocationCause) }}
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Comme « Mes connexions MCP » : un conteneur sans hauteur imposée n'a jamais à défiler à la verticale (#748, constat 18). */
.table-responsive {
  overflow-y: hidden;
}
</style>
