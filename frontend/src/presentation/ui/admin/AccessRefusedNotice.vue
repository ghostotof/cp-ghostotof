<script setup lang="ts">
import { computed, onBeforeUnmount, useTemplateRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { authState } from '../../../application/auth/useAuth'
import {
  backofficeAccessRefused,
  clearBackofficeAccessRefusal,
} from '../../../application/admin/shared/backofficeAccessRefusal'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { usePublishedHeight } from '../../layout/usePublishedHeight'
import { switchAccountLocation } from '../../router/reauthentication'

/**
 * L'alerte « accès refusé » du backoffice (#748, R17, F4), rendue **une seule fois** par `AdminLayout`, juste après
 * `SessionEndedBanner` : un 403 qui n'est pas une fin de session (une lecture, ou une écriture partie avec son
 * jeton) est un refus réel. `BackofficeHttpClient` lève `AccessForbiddenError`, que `useAdminLoad` et
 * `visibleMutationError` taisent ; la réaction commune (`createBackofficeSessionEndedHandler`) compte le refus et relit
 * le palier. Cette alerte est donc la seule annonce, pour les douze pages, sans un message de plus dans chacune.
 *
 * - `role="alert"`, inséré par `v-if` (refus > 0 **et** session non finie) : l'insertion est ce que les lecteurs
 *   d'écran annoncent. Elle suit un geste qui vient d'échouer, comme le bandeau de session finie.
 * - Un refus de plus **remplace le message** sans réinsérer l'alerte : seul le `<span>` du message porte la `key`
 *   (le nombre de refus), le lien n'est donc jamais détruit sous le doigt de la personne (une `role="alert"` est
 *   `aria-atomic` : le contenu changé se relit en entier).
 * - **Session finie : l'alerte s'efface** et ne revient pas avec la session. Le refus d'avant ne dit plus rien du
 *   compte qui reviendra, et le bandeau de session finie parle seul.
 * - « Changer de compte » (`common.switchAccount`) est un lien **dans le même onglet** (décision de l'auteur, comme
 *   « Mes connexions MCP » et l'assistant, R7) : `switchAccountLocation` ouvre la connexion à une personne déjà
 *   connectée (`reauth=1`) et `redirect` ramène à cette page. La saisie en cours est perdue en le suivant : la
 *   personne lit d'abord pourquoi, et rien ne la déplace pendant qu'elle lit. Le message est neutre
 *   (`common.accessRefused`) : le compte n'a peut-être pas, ou plus, l'accès, ou la session doit être rouverte.
 * - **Effacée à chaque changement de `route.fullPath`**, et au démontage du layout : un refus d'une page ne suit pas
 *   la personne sur la suivante.
 * - Collée sous l'en-tête comme le bandeau de session finie (page longue, « Enregistrer » en bas) ; les deux ne
 *   s'affichent jamais ensemble, elles publient donc la même hauteur (`--admin-notice-height`).
 */
const { t, locale } = useI18n()
const route = useRoute()

const switchAccountTarget = computed(() => switchAccountLocation(route, locale.value as Locale))
const isShown = computed(() => backofficeAccessRefused.value > 0 && !authState.sessionEnded)

const notice = useTemplateRef<HTMLElement>('notice')
usePublishedHeight(notice, '--admin-notice-height')

watch(
  () => route.fullPath,
  () => clearBackofficeAccessRefusal(),
)

watch(
  () => authState.sessionEnded,
  (isEnded) => {
    if (isEnded) {
      clearBackofficeAccessRefusal()
    }
  },
)

onBeforeUnmount(clearBackofficeAccessRefusal)
</script>

<template>
  <p
    v-if="isShown"
    ref="notice"
    role="alert"
    class="access-refused-notice text-danger-emphasis mb-0 d-flex flex-wrap align-items-center gap-3"
    data-testid="admin-access-refused"
  >
    <span :key="backofficeAccessRefused">{{ t('common.accessRefused') }}</span>
    <RouterLink :to="switchAccountTarget">
      {{ t('common.switchAccount') }}
    </RouterLink>
  </p>
</template>

<style scoped>
/* Collée sous l'en-tête comme le bandeau de session finie (`SessionEndedBanner`), au même `z-index` : sous les
   menus déroulants de l'en-tête (1030), lisible par-dessus le contenu qui défile. Fond opaque. */
.access-refused-notice {
  position: sticky;
  top: var(--app-header-height, 0px);
  z-index: 1010;
  padding-block: 0.75rem;
  background: var(--bs-body-bg);
  border-bottom: var(--bs-border-width) solid var(--bs-border-color);
}
</style>
