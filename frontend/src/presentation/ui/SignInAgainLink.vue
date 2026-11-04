<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useCurrentLocale } from '../../application/shared/useCurrentLocale'
import { signInAgainLocation } from '../router/sessionEnded'
import IconExternalLink from '~icons/lucide/external-link'

/**
 * Le lien de reconnexion d'une session finie, **dans un nouvel onglet** (#748, R6, Std3 et Std7) : le bandeau du
 * backoffice, l'en-tête, « Mes connexions MCP » et l'assistant le rendent, aucun ne compose plus le sien.
 *
 * Pourquoi un autre onglet : dans le même, la page serait quittée et ce que la personne a saisi, perdu. Le cookie
 * de session est partagé par les onglets, et `redirect` (le chemin courant, `signInAgainLocation`) ramène le
 * nouvel onglet à cette page ; la revérification au retour sur l'onglet d'origine rétablit la session.
 *
 * L'ouverture est dite au texte (WCAG 3.2.5, un texte caché `common.opensInNewTab`) et à l'œil (l'icône, cachée
 * aux lecteurs d'écran). `noopener` coupe l'accès à `window.opener`.
 *
 * **Le consentement OAuth l'utilise aussi, dans le même onglet** (R16, F7) : sa route porte `meta.signInInSameTab`,
 * lue ici sur la route courante. Le lien n'a alors ni `target`, ni `rel`, ni texte caché, ni icône — il n'ouvre
 * rien de nouveau. Raison : la page est une étape d'un flux qui finit par `location.replace` vers le client ; un
 * second onglet laisserait la demande ouverte dans le premier (`.claude/rules/frontend-session.md`). Le lien du
 * corps de la page et « Connexion » de l'en-tête sont ce même composant, donc la même règle.
 *
 * Le blanc entre le libellé et le texte caché est écrit (`{{ ' ' }}`) : Vue retire le blanc entre deux éléments, et un
 * lecteur d'écran lirait « Se reconnecter(s'ouvre… ». Un seul blanc avant l'icône, le texte caché étant hors flux.
 *
 * Une seule racine, `RouterLink` : les classes passées (le bouton de l'en-tête) s'y posent. L'emplacement par
 * défaut est le libellé visible.
 */
const { t } = useI18n()
const currentLocale = useCurrentLocale()
const route = useRoute()

const to = computed(() => signInAgainLocation(route, currentLocale.value))
/** La route courante garde l'onglet (le consentement) : le lien n'ouvre rien de nouveau. */
const inSameTab = computed(() => true === route.meta.signInInSameTab)
</script>

<template>
  <RouterLink
    :to="to"
    :target="inSameTab ? undefined : '_blank'"
    :rel="inSameTab ? undefined : 'noopener'"
  >
    <slot />
    <template v-if="!inSameTab">
      {{ ' ' }}<span class="visually-hidden">{{ t('common.opensInNewTab') }}</span>
      <IconExternalLink
        width="14"
        height="14"
        aria-hidden="true"
      />
    </template>
  </RouterLink>
</template>
