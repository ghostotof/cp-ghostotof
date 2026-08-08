<script setup lang="ts">
import { computed } from 'vue'
import { resolveIcon } from './icons'

const props = defineProps<{
  iconKey?: string
}>()

const icon = computed(() => resolveIcon(props.iconKey))
</script>

<template>
  <span class="badge-soft">
    <component :is="icon" v-if="icon" width="14" height="14" class="text-primary" aria-hidden="true" />
    <slot />
  </span>
</template>
