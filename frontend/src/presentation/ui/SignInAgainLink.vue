<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { Locale } from '../../domain/portfolio/entities/Locale'
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
 * Le consentement OAuth n'utilise pas ce composant : il garde ses liens dans le même onglet, étape d'un flux qui
 * finit par `location.replace` vers le client (`.claude/rules/frontend-session.md`).
 *
 * Le blanc entre le libellé et le texte caché est écrit (`{{ ' ' }}`) : Vue retire le blanc entre deux éléments, et un
 * lecteur d'écran lirait « Se reconnecter(s'ouvre… ». Un seul blanc avant l'icône, le texte caché étant hors flux.
 *
 * Une seule racine, `RouterLink` : les classes passées (le bouton de l'en-tête) s'y posent. L'emplacement par
 * défaut est le libellé visible.
 */
const { t, locale } = useI18n()
const route = useRoute()

const to = computed(() => signInAgainLocation(route, locale.value as Locale))
</script>

<template>
  <RouterLink
    :to="to"
    target="_blank"
    rel="noopener"
  >
    <slot />{{ ' ' }}<span class="visually-hidden">{{ t('common.opensInNewTab') }}</span>
    <IconExternalLink
      width="14"
      height="14"
      aria-hidden="true"
    />
  </RouterLink>
</template>
