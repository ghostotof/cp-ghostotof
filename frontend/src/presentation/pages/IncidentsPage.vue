<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIncidents } from '../../application/incidents/useIncidents'
import { parseIsoDate } from '../format/isoDate'
import RichText from '../ui/RichText.vue'

const { t, locale } = useI18n()
const { incidents, isLoading, hasError } = useIncidents()

/**
 * Le contrat public expose une date ISO ; la mise en forme reste ici, dans la
 * présentation, et suit la langue affichée.
 */
const dateFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { year: 'numeric', month: 'long', day: 'numeric' }))

function formatDate(isoDate: string): string {
  const parsed = parseIsoDate(isoDate)

  // Une date illisible ne doit pas faire disparaître l'incident : on retombe
  // sur la valeur brute plutôt que d'afficher « Invalid Date ».
  return null === parsed ? isoDate : dateFormatter.value.format(parsed)
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
        {{ t('incidents.eyebrow') }}
      </h1>

      <p class="text-body-secondary mb-0">
        {{ t('incidents.intro') }}
      </p>
    </div>

    <p
      v-if="isLoading"
      class="text-body-secondary mb-0"
    >
      {{ t('incidents.loading') }}
    </p>

    <p
      v-else-if="hasError"
      class="text-danger mb-0"
      role="alert"
    >
      {{ t('incidents.error') }}
    </p>

    <p
      v-else-if="incidents.length === 0"
      class="text-body-secondary mb-0"
    >
      {{ t('incidents.empty') }}
    </p>

    <article
      v-for="incident in incidents"
      v-else
      :key="incident.version + incident.title"
      class="surface-panel p-3 p-sm-4"
    >
      <!-- Pas de text-uppercase ici, contrairement aux autres eyebrows : il
           transformerait « v0.7.0 » en « V0.7.0 », et un numéro de version ne
           se capitalise pas. -->
      <p class="text-eyebrow small fw-semibold mb-2">
        {{ incident.version }} · <time :datetime="incident.occurredAt">{{ formatDate(incident.occurredAt) }}</time>
      </p>

      <h2 class="h5 text-white mb-4">
        {{ incident.title }}
      </h2>

      <div class="d-flex flex-column gap-3">
        <div
          v-for="field in (['impact', 'rootCause', 'resolution'] as const)"
          :key="field"
        >
          <h3 class="incident__label text-uppercase small fw-semibold mb-1">
            {{ t(`incidents.fields.${field}`) }}
          </h3>
          <RichText :text="incident[field]" />
        </div>
      </div>

      <!--
        L'invariant est traité à part, et pas comme un quatrième champ : c'est
        la raison d'être de la page. Une panne racontée sans la règle qui en
        sort n'est qu'un aveu ; c'est cette section qui fait la différence.
      -->
      <div class="incident__invariant mt-4 p-3">
        <h3 class="incident__label incident__label--invariant text-uppercase small fw-semibold mb-1">
          {{ t('incidents.fields.invariant') }}
        </h3>
        <RichText
          :text="incident.invariant"
          paragraph-class="mb-0 text-white-50"
        />
      </div>
    </article>
  </section>
</template>

<style scoped>
.incident__label {
  color: var(--bs-secondary-color);
  letter-spacing: 0.06em;
}

.incident__label--invariant {
  color: var(--bs-link-color);
}

.incident__invariant {
  border-left: 3px solid var(--bs-link-color);
  border-radius: 0 0.5rem 0.5rem 0;
  background: rgba(124, 58, 237, 0.08);
}
</style>
