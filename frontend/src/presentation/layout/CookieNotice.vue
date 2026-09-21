<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCookieNotice } from '../../application/cookieNotice/useCookieNotice'

/**
 * Bandeau d'information sur les cookies (cf. useCookieNotice : information, pas
 * consentement — un seul bouton, aucun choix à faire).
 *
 * Non bloquant par construction : ni modale, ni piège de focus, ni overlay. Il
 * est rendu en fin de layout et collé en bas par `position: sticky`, pas
 * `fixed` : il occupe sa place dans le flux, donc il ne recouvre jamais le pied
 * de page une fois la page défilée jusqu'en bas. Fond opaque — un panneau
 * translucide ferait dépendre le contraste du texte de ce qui défile dessous.
 */
const { t, locale } = useI18n()
const { isVisible, dismiss } = useCookieNotice()

const privacyPolicyPath = computed(() => `/${locale.value}/privacy-policy`)
</script>

<template>
  <section
    v-if="isVisible"
    class="cookie-notice"
    :aria-label="t('cookieNotice.label')"
  >
    <div class="container d-flex flex-column flex-md-row align-items-md-center gap-3 py-3">
      <p class="mb-0 small flex-grow-1">
        {{ t('cookieNotice.message') }}
        <RouterLink :to="privacyPolicyPath">
          {{ t('cookieNotice.learnMore') }}
        </RouterLink>
      </p>
      <button
        type="button"
        class="btn btn-outline-light btn-sm flex-shrink-0"
        @click="dismiss"
      >
        {{ t('cookieNotice.dismiss') }}
      </button>
    </div>
  </section>
</template>

<style scoped>
.cookie-notice {
  position: sticky;
  bottom: 0;
  z-index: 1020;
  background: var(--bs-body-bg);
  border-top: 1px solid var(--bs-border-color);
}
</style>
