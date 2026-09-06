<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { CallToActionVariant } from '../../domain/portfolio/entities/CallToAction'
import { resolveIcon } from './icons'

const props = withDefaults(
  defineProps<{
    href: string
    variant?: CallToActionVariant
    iconKey?: string
    isExternal?: boolean
  }>(),
  {
    variant: 'primary',
    iconKey: undefined,
    isExternal: false,
  },
)

const { t } = useI18n()

const icon = computed(() => resolveIcon(props.iconKey))
const buttonClass = computed(() => (props.variant === 'primary' ? 'btn-gradient' : 'btn-outline-light'))

// Cible interne : RouterLink, et non une ancre brute. Un <a href="/fr/about">
// provoque un rechargement complet du document — bundle rejoué, état de
// l'application perdu, écran blanc le temps du chargement — là où la SPA n'a
// qu'un composant à monter. Balise écrite littéralement, comme dans AppHeader.
//
// `href` reste le nom de la propriété côté appelant : la cible vient de
// CallToAction.href, un chemin déjà localisé et volontairement typé `string`
// pour garder le domaine sans dépendance à vue-router (même choix que
// NavigationLink.to).
</script>

<template>
  <a
    v-if="isExternal"
    :href="href"
    target="_blank"
    rel="noopener noreferrer"
    class="btn d-inline-flex align-items-center gap-2"
    :class="buttonClass"
  >
    <slot />
    <span class="visually-hidden">{{ t('common.opensInNewTab') }}</span>
    <component
      :is="icon"
      v-if="icon"
      width="16"
      height="16"
      aria-hidden="true"
    />
  </a>
  <RouterLink
    v-else
    :to="href"
    class="btn d-inline-flex align-items-center gap-2"
    :class="buttonClass"
  >
    <slot />
    <component
      :is="icon"
      v-if="icon"
      width="16"
      height="16"
      aria-hidden="true"
    />
  </RouterLink>
</template>
