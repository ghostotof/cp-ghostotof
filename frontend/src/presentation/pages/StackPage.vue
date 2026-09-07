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
const products = computed(() => releaseCycles.value?.products ?? [])
const refreshedAt = computed(() => releaseCycles.value?.refreshedAt ?? null)
const isPartial = computed(() => releaseCycles.value?.sourceStatus === 'partial')

const deadlineFormatter = computed(
  () => new Intl.DateTimeFormat(locale.value, { year: 'numeric', month: 'long' }),
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

    <div
      v-else
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

              <td>{{ formatDeadline(product.endOfActiveSupportFrom) }}</td>
              <td>{{ formatDeadline(product.eolFrom) }}</td>

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
