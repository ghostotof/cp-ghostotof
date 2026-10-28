<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuth } from '../../../application/auth/useAuth'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { SESSION_ENDED_QUERY, SESSION_ENDED_VALUE } from '../../router/sessionEnded'

/**
 * Le bandeau « Votre session a pris fin » du backoffice (#650, S2), rendu une
 * seule fois par `AdminLayout` : un 401 d'une page d'administration ferme la
 * session (`onBackofficeUnauthorized`) mais **ne déplace pas la personne** — la
 * page reste montée, avec l'étude de cas ou la carte à moitié rédigée.
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
 */
const { t, locale } = useI18n()
const route = useRoute()
const { hasSessionEnded, checkAuth } = useAuth()

const signInAgainLocation = computed(() => ({
  name: 'login',
  params: { locale: locale.value as Locale },
  query: { redirect: route.fullPath, [SESSION_ENDED_QUERY]: SESSION_ENDED_VALUE },
}))

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
  <p
    v-if="hasSessionEnded"
    role="alert"
    class="text-danger mb-0 d-flex flex-wrap align-items-center gap-3"
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
    </RouterLink>
  </p>
</template>
