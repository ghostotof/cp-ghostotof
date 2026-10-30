<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { authState, lastAccountName } from '../../../application/auth/useAuth'
import {
  backofficeAccessRefused,
  clearBackofficeAccessRefusal,
  earlierSaveRefused,
  settleBackofficeAccessRefusalsOnMount,
} from '../../../application/admin/shared/backofficeAccessRefusal'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { usePublishedHeight } from '../../layout/usePublishedHeight'
import { switchAccountLocation } from '../../router/reauthentication'

/**
 * L'alerte « accès refusé » du backoffice (#748, R17, F4), rendue **une seule fois** par `AdminLayout`, juste après
 * `SessionEndedBanner` : un 403 qui n'est pas une fin de session (une lecture, ou une écriture partie avec son
 * jeton) est un refus réel. `BackofficeHttpClient` lève `AccessForbiddenError`, que `useAdminLoad` et
 * `visibleMutationError` taisent ; la réaction commune (`createBackofficeSessionReaction`) compte le refus et relit
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
 * - **Effacée** (le périmètre de refus avance, `refusalScope()`) à chaque changement de chemin ou de requête
 *   (`route.path` et `route.query`, paramètres triés), à une fin de session et au démontage du layout. **Pas le
 *   fragment** (R38) : le lien d'évitement (`href="#main-content"`) ne change que lui, et effaçait l'alerte que la
 *   personne était en train de lire. Un refus d'une page ne suit pas la personne sur la suivante : le 403 d'une
 *   lecture partie avant la navigation, arrivé après, est périmé et ne s'affiche pas (R28, CR3-8) ; celui d'une
 *   écriture s'affiche (R38). Un effacement oublie aussi l'écriture comptée et le compte qui l'a envoyée (R76).
 * - **Le montage n'efface rien et ne change pas le périmètre ; il règle ce qui a été compté sans alerte** (R64,
 *   décision 1 de la sixième relecture, amendant Q1 de la cinquième ; `settleBackofficeAccessRefusalsOnMount`) : une
 *   lecture comptée alors qu'aucune alerte n'était montée est oubliée (le palier a été relu), un enregistrement refusé
 *   pendant que la personne avait quitté le backoffice est **dit au retour**, avec son propre texte
 *   (`common.earlierSaveRefused`, `earlierSaveRefused`) — **au compte qui l'a envoyé seulement** (R76, décision 2 de la
 *   septième relecture : `lastAccountName()` est passé au montage, un autre compte remet tout à zéro). Le chargement de
 *   la page d'arrivée est parti avant le montage : son refus doit compter, et **s'il est refusé, l'avis reste** (CR7-4 :
 *   la **première** lecture refusée après le montage avance le compteur, réannonce l'alerte et garde le texte). Tout
 *   refus suivant, **lecture** (une relecture refusée plus tard, rôle perdu) **ou écriture**, remet `common.accessRefused`
 *   (R95, décision 4 de la huitième relecture) : ce n'est plus le chargement, ou c'est un nouvel enregistrement refusé.
 * - **Insérée après le montage** (`isMounted`) : une région `role="alert"` présente dès le premier rendu peut ne pas
 *   être annoncée (`.claude/rules/frontend-a11y.md`) ; celle d'un enregistrement refusé pendant l'absence est donc
 *   insérée un tick plus tard, comme celle d'un refus survenu sur la page.
 * - Collée sous l'en-tête comme le bandeau de session finie (page longue, « Enregistrer » en bas) ; les deux ne
 *   s'affichent jamais ensemble, elles publient donc la même hauteur (`--admin-notice-height`).
 */
const { t, locale } = useI18n()
const route = useRoute()

const switchAccountTarget = computed(() => switchAccountLocation(route, locale.value as Locale))
// Faux au premier rendu : l'alerte est insérée après le montage, donc annoncée même quand un enregistrement refusé
// pendant l'absence l'attend déjà (R64).
const isMounted = ref(false)
const isShown = computed(() => isMounted.value && backofficeAccessRefused.value > 0 && !authState.sessionEnded)
const message = computed(() => (earlierSaveRefused.value ? t('common.earlierSaveRefused') : t('common.accessRefused')))

const notice = useTemplateRef<HTMLElement>('notice')
usePublishedHeight(notice, '--admin-notice-height')

// Le chemin et la requête, jamais le fragment (le lien d'évitement ne change que lui, R38). Les paramètres sont triés :
// vue-router exécute une navigation qui ne fait que les permuter (`?a=1&b=2` → `?b=2&a=1`, mesuré en R54, CR5-8), mais
// la page est la même, et l'alerte d'un refus ne doit pas disparaître pour un ordre.
watch(
  () => route.path + JSON.stringify(Object.entries(route.query).sort(([a], [b]) => a.localeCompare(b))),
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

// Au montage, règle ce qui a été compté sans alerte (R64) : une lecture est oubliée, une écriture refusée pendant
// l'absence est dite au compte qui l'a envoyée (R76), à nul autre. Le périmètre n'avance pas : le chargement de la page d'arrivée est parti avant ce montage, son
// refus doit compter. Au démontage, tout s'efface.
onMounted(() => {
  settleBackofficeAccessRefusalsOnMount(lastAccountName())
  isMounted.value = true
})
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
    <span :key="backofficeAccessRefused">{{ message }}</span>
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
