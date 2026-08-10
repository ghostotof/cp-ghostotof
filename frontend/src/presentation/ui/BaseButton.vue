<script setup lang="ts">
import { computed } from 'vue'
import type { CallToActionVariant } from '../../domain/portfolio/entities/CallToAction'
import { resolveIcon } from './icons'

const props = withDefaults(
  defineProps<{
    href: string
    variant?: CallToActionVariant
    iconKey?: string
  }>(),
  {
    variant: 'primary',
    iconKey: undefined,
  },
)

const icon = computed(() => resolveIcon(props.iconKey))
const buttonClass = computed(() => (props.variant === 'primary' ? 'btn-gradient' : 'btn-outline-light'))
</script>

<template>
  <a
    :href="href"
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
  </a>
</template>
