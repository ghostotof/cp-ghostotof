<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuth } from '../../../application/auth/useAuth'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { SESSION_ENDED_QUERY, SESSION_ENDED_VALUE } from '../../router/sessionEnded'
import { usePublishedHeight } from '../../layout/usePublishedHeight'
import IconExternalLink from '~icons/lucide/external-link'

/**
 * Le bandeau « Votre session a pris fin » du backoffice (#650, S2), rendu une
 * seule fois par `AdminLayout` : un 401 d'une page d'administration ferme la
 * session (`onBackofficeSessionEnded`) mais **ne déplace pas la personne** — la
 * page reste montée, avec l'étude de cas ou la carte à moitié rédigée. Ce qui finit une session :
 * voir `infrastructure/http/sessionAwareFetch.ts` ; la règle de référence est
 * `.claude/rules/frontend-session.md`.
 *
 * - `role="alert"` : l'annonce doit interrompre. Elle suit un geste (« Enregistrer »)
 *   qui vient d'échouer, et rien d'autre ne marchera tant que la personne n'a pas
 *   agi. Le bandeau est inséré à ce moment-là (`v-if`), ce que les lecteurs d'écran
 *   annoncent ; contrairement à la région de LoginPage, présente dès le premier rendu.
 * - Aucun `focus()` : la personne est peut-être au milieu d'une phrase. Le lien est
 *   dans l'ordre de lecture, atteignable au clavier.
 * - Le lien s'ouvre dans un **autre onglet** : dans le même, la page serait quittée
 *   et la saisie perdue. Le cookie de session est partagé par les onglets, et
 *   `redirect` ramène l'autre onglet à cette page. L'ouverture est dite dans le
 *   texte du lien (WCAG 3.2.5), `noopener` coupe l'accès à `window.opener`.
 * - Au retour sur cet onglet (`focus`), tant que la session est finie, la session
 *   est relue : un compte retrouvé efface le bandeau, et « Enregistrer » refonctionne
 *   (le jeton CSRF est relu du cookie à chaque appel). Sinon le bandeau resterait
 *   affiché après la reconnexion.
 * - **Collant sous l'en-tête** (#748, constat 7) : sur une page longue, le bandeau défilait hors de
 *   l'écran et la personne ne savait plus pourquoi « Enregistrer » ne répondait pas. Sa hauteur est
 *   publiée (`--admin-notice-height`) pour que `scroll-padding-top` (style.css) garde le champ focalisé
 *   hors de dessous ; il se colle sous l'en-tête, dont la hauteur change (`--app-header-height`).
 * - **Le retour est annoncé** (#748, constat 14) : quand le bandeau disparaît, rien ne dit que « Enregistrer »
 *   refonctionne. Une région `role="status"` **présente dès le montage** (une région insérée avec son texte
 *   peut ne pas être annoncée) reçoit alors « Session rétablie » ; vide, elle est masquée visuellement, et
 *   elle se vide à la navigation suivante.
 */
const { t, locale } = useI18n()
const route = useRoute()
const { hasSessionEnded, checkAuth } = useAuth()

const signInAgainLocation = computed(() => ({
  name: 'login',
  params: { locale: locale.value as Locale },
  query: { redirect: route.fullPath, [SESSION_ENDED_QUERY]: SESSION_ENDED_VALUE },
}))

// Hauteur du bandeau, publiée tant qu'il est affiché (retirée au `v-if` faux).
const banner = useTemplateRef<HTMLElement>('banner')
usePublishedHeight(banner, '--admin-notice-height')

/** Vrai entre le retour de la session et la navigation suivante : la région `status` porte alors son message. */
const hasRestored = ref(false)

watch(hasSessionEnded, (isEnded, wasEnded) => {
  if (wasEnded && !isEnded) {
    hasRestored.value = true
  }
})

watch(
  () => route.fullPath,
  () => {
    hasRestored.value = false
  },
)

async function recheckSession(): Promise<void> {
  if (!hasSessionEnded.value) {
    return
  }

  try {
    await checkAuth()
  } catch {
    // Le réseau ne répond pas : le bandeau reste, la personne peut recommencer au prochain retour.
  }
}

onMounted(() => {
  window.addEventListener('focus', recheckSession)
})

onBeforeUnmount(() => {
  window.removeEventListener('focus', recheckSession)
})
</script>

<template>
  <div>
    <p
      v-if="hasSessionEnded"
      ref="banner"
      role="alert"
      class="session-ended-banner text-danger-emphasis mb-0 d-flex flex-wrap align-items-center gap-3"
      data-testid="admin-session-ended"
    >
      {{ t('admin.sessionEnded.message') }}
      <RouterLink
        :to="signInAgainLocation"
        target="_blank"
        rel="noopener"
      >
        {{ t('admin.sessionEnded.signInAgain') }}
        <span class="visually-hidden">{{ t('admin.sessionEnded.newTab') }}</span>
        <IconExternalLink
          width="14"
          height="14"
          aria-hidden="true"
        />
      </RouterLink>
    </p>

    <!-- Présente dès le montage, vide : c'est le changement de son contenu qui est annoncé. -->
    <div
      role="status"
      class="small text-success-emphasis"
      :class="{ 'visually-hidden': !hasRestored }"
      data-testid="admin-session-restored"
    >
      {{ hasRestored ? t('admin.sessionEnded.restored') : '' }}
    </div>
  </div>
</template>

<style scoped>
/* Collé sous l'en-tête (position: sticky-top, z-index 1020 de Bootstrap) : le bandeau passe SOUS ses menus
   déroulants (1030) mais reste lisible par-dessus le contenu qui défile. Fond opaque, sinon le texte qui défile
   dessous se lit à travers. La hauteur de l'en-tête est publiée par AppHeader (usePublishedHeight). */
.session-ended-banner {
  position: sticky;
  top: var(--app-header-height, 0px);
  z-index: 1010;
  padding-block: 0.75rem;
  background: var(--bs-body-bg);
  border-bottom: var(--bs-border-width) solid var(--bs-border-color);
}
</style>
