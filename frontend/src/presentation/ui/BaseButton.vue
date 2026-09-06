<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
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

// `noopener` empêche la page ouverte d'accéder à window.opener (détournement
// par tabnabbing) ; `noreferrer` évite en prime de fuiter l'URL d'origine.
const target = computed(() => (props.isExternal ? '_blank' : undefined))
const rel = computed(() => (props.isExternal ? 'noopener noreferrer' : undefined))
</script>

<template>
  <a
    :href="href"
    :target="target"
    :rel="rel"
    class="btn d-inline-flex align-items-center gap-2"
    :class="buttonClass"
  >
    <slot />
    <!--
      Un lien qui ouvre un onglet change le contexte de navigation sans
      prévenir : sans cette mention, un lecteur d'écran annonce un lien
      ordinaire et l'utilisateur ne comprend pas pourquoi le retour arrière
      ne fonctionne plus.
    -->
    <span
      v-if="isExternal"
      class="visually-hidden"
    >{{ t('common.opensInNewTab') }}</span>
    <component
      :is="icon"
      v-if="icon"
      width="16"
      height="16"
      aria-hidden="true"
    />
  </a>
</template>
