<script setup lang="ts">
import { ref, useTemplateRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuth } from '../../../application/auth/useAuth'
import { usePublishedHeight } from '../../layout/usePublishedHeight'
import SignInAgainLink from '../SignInAgainLink.vue'

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
 * - Le lien est `SignInAgainLink` : un **autre onglet** (dans le même, la page serait quittée
 *   et la saisie perdue), `redirect` y ramène, l'ouverture est dite au texte et à l'œil.
 * - **Il n'écoute pas le `focus`** (#748, R8) : la revérification au retour sur l'onglet est celle
 *   d'`AppLayout` (`useSessionRecheckOnFocus`), la même pour toutes les pages protégées ; un compte retrouvé
 *   avec son jeton d'écriture fait retomber `hasSessionEnded`, ce qui efface le bandeau et annonce le retour.
 * - **Collant sous l'en-tête** (#748, constat 7) : sur une page longue, le bandeau défilait hors de
 *   l'écran et la personne ne savait plus pourquoi « Enregistrer » ne répondait pas. Sa hauteur est
 *   publiée (`--admin-notice-height`) pour que `scroll-padding-top` (style.css) garde le champ focalisé
 *   hors de dessous ; il se colle sous l'en-tête, dont la hauteur change (`--app-header-height`).
 * - **Deux racines** (#748, R9) : l'alerte et la région `status` sont les enfants directs de la `<section>`
 *   d'`AdminLayout`, qui contient la page. C'est le bloc contenant de `position: sticky` : l'alerte colle sur toute
 *   la hauteur de la page. Vides, les deux n'entrent pas dans le flux (rien de rendu, ou `visually-hidden`), donc
 *   aucun `gap` de plus.
 * - **Chaque refus réannonce** (S7) : l'alerte est insérée avec `:key="sessionEndedCount"`, que
 *   `markSessionExpired()` augmente à chaque refus ; **« Session rétablie » s'efface** si la session finit de
 *   nouveau (CR6).
 * - **Le retour est annoncé** (#748, constat 14) : quand le bandeau disparaît, rien ne dit que « Enregistrer »
 *   refonctionne. Une région `role="status"` **présente dès le montage** (une région insérée avec son texte
 *   peut ne pas être annoncée) reçoit alors « Session rétablie » ; vide, elle est masquée visuellement, et
 *   elle se vide à la navigation suivante.
 */
const { t } = useI18n()
const route = useRoute()
const { hasSessionEnded, sessionEndedCount } = useAuth()

// Hauteur du bandeau, publiée tant qu'il est affiché (retirée au `v-if` faux).
const banner = useTemplateRef<HTMLElement>('banner')
usePublishedHeight(banner, '--admin-notice-height')

/** Vrai entre le retour de la session et la navigation suivante : la région `status` porte alors son message. */
const hasRestored = ref(false)

watch(hasSessionEnded, (isEnded, wasEnded) => {
  if (isEnded) {
    // La session finit de nouveau : « vous pouvez enregistrer » serait faux sous un bandeau qui dit le contraire (CR6).
    hasRestored.value = false
  } else if (wasEnded) {
    hasRestored.value = true
  }
})

watch(
  () => route.fullPath,
  () => {
    hasRestored.value = false
  },
)
</script>

<template>
  <!--
    Deux racines, pas un `<div>` qui les enveloppe (#748, CR1/D1). Un élément `position: sticky` ne colle que dans
    son parent : enveloppée, l'alerte avait pour parent un `<div>` haut exactement d'elle-même, sans course, et elle
    défilait avec lui. Ici le parent est la `<section>` d'AdminLayout, qui contient toute la page. Et ce `<div>`,
    toujours rendu, était un élément de plus dans le `flex-column gap-4` de la section : un `gap` d'espace vide.
    Aucun attribut ne tombe sur ce composant (AdminLayout ne lui en passe pas), donc pas d'héritage ambigu.
    `display: contents` écarté : une règle CSS de plus, et des pertes de sémantique connues dans Safari.
  -->
  <!--
    `:key` = nombre de refus : un refus de plus pendant que le bandeau est affiché le réinsère, donc le réannonce
    (S7) ; plusieurs refus du même tick ne font qu'un rendu, donc qu'une insertion.
  -->
  <p
    v-if="hasSessionEnded"
    ref="banner"
    :key="sessionEndedCount"
    role="alert"
    class="session-ended-banner text-danger-emphasis mb-0 d-flex flex-wrap align-items-center gap-3"
    data-testid="admin-session-ended"
  >
    {{ t('admin.sessionEnded.message') }}
    <SignInAgainLink>{{ t('admin.sessionEnded.signInAgain') }}</SignInAgainLink>
  </p>

  <!-- Présente dès le montage, vide : c'est le changement de son contenu qui est annoncé. `visually-hidden` la sort du flux. -->
  <div
    role="status"
    class="small text-success-emphasis"
    :class="{ 'visually-hidden': !hasRestored }"
    data-testid="admin-session-restored"
  >
    {{ hasRestored ? t('admin.sessionEnded.restored') : '' }}
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
