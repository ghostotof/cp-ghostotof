<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWatch } from '../../application/watch/useWatch'
import type { SupportStatus } from '../../domain/watch/entities/WatchContent'

const { t, locale } = useI18n()
const { content, isLoading, hasError } = useWatch()

/**
 * Correspondances explicites plutôt qu'une clé construite à la volée : une
 * `t('stack.status.' + status)` échapperait à la vérification d'usage des clés
 * par @intlify, et un statut ajouté côté backend passerait inaperçu jusqu'à
 * l'affichage d'une clé brute en production.
 */
const STATUS_LABEL_KEYS: Record<SupportStatus, string> = {
  supported: 'stack.status.supported',
  security_only: 'stack.status.securityOnly',
  eol: 'stack.status.eol',
  unknown: 'stack.status.unknown',
}

const STATUS_BADGE_CLASSES: Record<SupportStatus, string> = {
  supported: 'text-bg-success',
  security_only: 'text-bg-warning',
  eol: 'text-bg-danger',
  unknown: 'text-bg-secondary',
}

const releaseCycles = computed(() => content.value?.releaseCycles ?? null)
const vulnerabilities = computed(() => content.value?.vulnerabilities ?? null)

/**
 * Trois états, à ne surtout pas confondre : l'analyse n'a pas eu lieu, elle
 * n'a rien trouvé, ou elle a trouvé. Le premier ressemble au deuxième si on
 * n'y prend pas garde, et afficher « aucune vulnérabilité » sans avoir cherché
 * serait le mensonge le plus confortable de cette page.
 */
const vulnerabilityState = computed<'unscanned' | 'healthy' | 'affected'>(() => {
  if (null === vulnerabilities.value || null === vulnerabilities.value.packagesScanned) return 'unscanned'

  return vulnerabilities.value.affectedCount > 0 ? 'affected' : 'healthy'
})
const products = computed(() => releaseCycles.value?.products ?? [])
const refreshedAt = computed(() => releaseCycles.value?.refreshedAt ?? null)
const isPartial = computed(() => releaseCycles.value?.sourceStatus === 'partial')

/**
 * En deçà de ce délai, une échéance cesse d'être une note de bas de page.
 * Douze mois laissent le temps de planifier une montée de version sans que
 * l'alerte se déclenche pour tout le tableau.
 */
const IMMINENT_THRESHOLD_MONTHS = 12

const deadlineFormatter = computed(
  () => new Intl.DateTimeFormat(locale.value, { year: 'numeric', month: 'long' }),
)

/**
 * `Intl.RelativeTimeFormat` plutôt que des clés traduites avec pluriel : la
 * langue, l'accord et le choix entre « dans 4 mois » et « le mois prochain »
 * sont l'affaire du navigateur, pas du fichier de traduction.
 */
const relativeFormatter = computed(
  () => new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' }),
)

const refreshedAtFormatter = computed(
  () =>
    new Intl.DateTimeFormat(locale.value, {
      dateStyle: 'long',
      timeStyle: 'short',
    }),
)

/**
 * Les échéances sont des dates de calendrier : le mois et l'année suffisent, et
 * afficher un jour précis pour un événement à trois ans donnerait une fausse
 * impression de précision.
 */
function formatDeadline(isoDate: string | null): string {
  if (isoDate === null) return '—'

  const parsed = new Date(`${isoDate}T00:00:00`)

  return Number.isNaN(parsed.getTime()) ? isoDate : deadlineFormatter.value.format(parsed)
}

function formatRefreshedAt(iso: string): string {
  const parsed = new Date(iso)

  return Number.isNaN(parsed.getTime()) ? iso : refreshedAtFormatter.value.format(parsed)
}

/** Nombre de mois entiers d'ici l'échéance ; négatif si elle est passée. */
function monthsUntil(isoDate: string): number | null {
  const parsed = new Date(`${isoDate}T00:00:00`)

  if (Number.isNaN(parsed.getTime())) return null

  const today = new Date()

  return (parsed.getFullYear() - today.getFullYear()) * 12 + (parsed.getMonth() - today.getMonth())
}

/**
 * « janvier 2027 » ne dit rien à qui ne fait pas le calcul. « dans 4 mois »,
 * si — et c'est toute la différence entre un tableau de dates et une veille.
 */
function relativeDeadline(isoDate: string | null): string | null {
  if (null === isoDate) return null

  const months = monthsUntil(isoDate)

  return null === months ? null : relativeFormatter.value.format(months, 'month')
}

function isImminent(isoDate: string | null): boolean {
  if (null === isoDate) return false

  const months = monthsUntil(isoDate)

  return null !== months && months <= IMMINENT_THRESHOLD_MONTHS
}
</script>

<template>
  <section class="container-xl py-5 d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h1 class="d-flex align-items-center gap-2 text-eyebrow text-uppercase small fw-semibold mb-3">
        <span
          class="rounded-circle bg-primary"
          style="width: 0.4rem; height: 0.4rem"
          aria-hidden="true"
        />
        {{ t('stack.eyebrow') }}
      </h1>

      <p class="text-body-secondary mb-0">
        {{ t('stack.intro') }}
      </p>
    </div>

    <p
      v-if="isLoading"
      class="text-body-secondary mb-0"
    >
      {{ t('stack.loading') }}
    </p>

    <p
      v-else-if="hasError"
      class="text-danger mb-0"
      role="alert"
    >
      {{ t('stack.error') }}
    </p>

    <!-- Aucun rafraîchissement encore abouti : le dire explicitement plutôt que
         d'afficher un tableau vide, qui se lirait comme « rien à signaler ». -->
    <p
      v-else-if="refreshedAt === null"
      class="text-body-secondary mb-0"
    >
      {{ t('stack.neverRefreshed') }}
    </p>

    <!-- Volet vulnérabilités : un décompte, jamais le détail (décision D4).
         Le dire explicitement vaut mieux que de laisser croire à un oubli. -->
    <div
      v-if="!isLoading && !hasError && vulnerabilities"
      class="surface-panel p-3 p-sm-4"
    >
      <h2 class="h6 fw-bold text-white mb-2">
        {{ t('stack.vulnerabilities.title') }}
      </h2>

      <p
        v-if="'unscanned' === vulnerabilityState"
        class="text-body-secondary mb-0"
      >
        {{ t('stack.vulnerabilities.notScanned') }}
      </p>

      <template v-else-if="'healthy' === vulnerabilityState">
        <p class="mb-0">
          <span class="badge text-bg-success me-2">{{ t('stack.vulnerabilities.healthyBadge') }}</span>
          {{ t('stack.vulnerabilities.healthy', { scanned: vulnerabilities.packagesScanned }) }}
        </p>
      </template>

      <template v-else>
        <p
          class="mb-1"
          role="status"
        >
          <span class="badge text-bg-danger me-2">{{ vulnerabilities.affectedCount }}</span>
          {{ t('stack.vulnerabilities.affected', { count: vulnerabilities.affectedCount, scanned: vulnerabilities.packagesScanned }, vulnerabilities.affectedCount) }}
        </p>
        <p class="form-text mb-0">
          {{ t('stack.vulnerabilities.detailRestricted') }}
        </p>
      </template>

      <p
        v-if="vulnerabilities.checkedAt"
        class="text-body-secondary small mb-0 mt-2"
      >
        {{ t('stack.vulnerabilities.source') }} ·
        <time :datetime="vulnerabilities.checkedAt">{{ t('stack.vulnerabilities.checkedAt', { date: formatRefreshedAt(vulnerabilities.checkedAt) }) }}</time>
      </p>
      <!-- Une analyse qui date n'est pas fausse, elle est incomplète : une
           faille publiée depuis n'y figure pas. Le dire vaut mieux que de
           laisser lire un chiffre rassurant comme s'il était d'aujourd'hui. -->
      <p
        v-if="'stale' === vulnerabilities.freshness"
        class="text-warning small mb-0"
        role="status"
      >
        {{ t('stack.vulnerabilities.stale') }}
      </p>
    </div>

    <div
      v-if="!isLoading && !hasError && refreshedAt !== null"
      class="surface-panel p-3 p-sm-4"
    >
      <p
        v-if="isPartial"
        class="text-warning small"
        role="status"
      >
        {{ t('stack.partial') }}
      </p>

      <div class="table-responsive">
        <table class="table table-dark table-borderless align-middle mb-0">
          <caption class="visually-hidden">
            {{ t('stack.tableCaption') }}
          </caption>
          <thead>
            <tr class="text-body-secondary small text-uppercase">
              <th scope="col">
                {{ t('stack.columns.product') }}
              </th>
              <th scope="col">
                {{ t('stack.columns.version') }}
              </th>
              <th scope="col">
                {{ t('stack.columns.status') }}
              </th>
              <th scope="col">
                {{ t('stack.columns.activeSupport') }}
              </th>
              <th scope="col">
                {{ t('stack.columns.eol') }}
              </th>
              <th scope="col">
                {{ t('stack.columns.latest') }}
              </th>
              <th scope="col">
                {{ t('stack.columns.update') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="product in products"
              :key="product.slug"
            >
              <th
                scope="row"
                class="fw-semibold text-white"
              >
                <a
                  v-if="product.documentationUrl"
                  :href="product.documentationUrl"
                  rel="noreferrer"
                  class="link-light"
                >{{ product.label }}</a>
                <template v-else>
                  {{ product.label }}
                </template>
              </th>

              <td>{{ product.version ?? '—' }}</td>

              <td>
                <!-- Le libellé est écrit en toutes lettres : la couleur du
                     badge ne porte jamais seule l'information. -->
                <span
                  class="badge"
                  :class="STATUS_BADGE_CLASSES[product.status]"
                >{{ t(STATUS_LABEL_KEYS[product.status]) }}</span>
              </td>

              <!-- La date absolue reste la donnée, le relatif la rend lisible.
                   L'échéance proche est signalée par le texte autant que par la
                   couleur : « dans 4 mois » se lit sans distinguer les teintes. -->
              <td>
                {{ formatDeadline(product.endOfActiveSupportFrom) }}
                <span
                  v-if="relativeDeadline(product.endOfActiveSupportFrom)"
                  class="d-block small"
                  :class="isImminent(product.endOfActiveSupportFrom) ? 'text-warning fw-semibold' : 'text-body-secondary'"
                >{{ relativeDeadline(product.endOfActiveSupportFrom) }}</span>
              </td>
              <td>
                {{ formatDeadline(product.eolFrom) }}
                <span
                  v-if="relativeDeadline(product.eolFrom)"
                  class="d-block small"
                  :class="isImminent(product.eolFrom) ? 'text-warning fw-semibold' : 'text-body-secondary'"
                >{{ relativeDeadline(product.eolFrom) }}</span>
              </td>

              <td>{{ product.latestVersion ?? '—' }}</td>

              <!-- Colonne dédiée plutôt qu'un badge accolé à la version : c'est
                   l'information la plus actionnable du tableau, elle se lit
                   mieux alignée verticalement que noyée dans une cellule. -->
              <td>
                <span
                  v-if="product.hasNewerPatch"
                  class="badge text-bg-info"
                >{{ t('stack.patchAvailable') }}</span>
                <span v-else>
                  —
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="text-body-secondary small mb-0 mt-3">
        {{ t('stack.source') }} ·
        <time :datetime="refreshedAt">{{ t('stack.refreshed', { date: formatRefreshedAt(refreshedAt) }) }}</time>
      </p>
      <p
        v-if="'stale' === releaseCycles?.freshness"
        class="text-warning small mb-0"
        role="status"
      >
        {{ t('stack.stale') }}
      </p>
    </div>
  </section>
</template>

<style scoped>
/*
 * `.table-responsive` de Bootstrap ne pose que `overflow-x: auto`, mais la
 * règle CSS veut qu'un `overflow` `visible` combiné à une autre valeur devienne
 * `auto` : l'axe vertical passe donc lui aussi en `auto`. La hauteur du tableau
 * étant fractionnaire (317,3 px mesurés), il manque un pixel — assez pour faire
 * apparaître une barre de défilement verticale qui rogne la dernière ligne.
 *
 * Le tableau n'a jamais à défiler verticalement : seule la largeur peut
 * déborder sur mobile.
 */
.table-responsive {
  overflow-y: hidden;
}
</style>
