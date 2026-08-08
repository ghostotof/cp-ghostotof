<script setup lang="ts">
import { computed } from 'vue'
import { resolveIcon } from './icons'

const props = defineProps<{
  title: string
  description?: string
  iconKey?: string
  highlighted?: boolean
}>()

const icon = computed(() => resolveIcon(props.iconKey))
</script>

<template>
  <div class="card-portfolio p-3 p-sm-4" :class="{ 'card-portfolio--highlighted': highlighted }">
    <div class="d-flex align-items-center gap-2 mb-2">
      <component :is="icon" v-if="icon" width="24" height="24" class="text-primary flex-shrink-0" aria-hidden="true" />
      <h3 class="h6 mb-0 text-white">{{ title }}</h3>
    </div>
    <p v-if="description" class="small text-body-secondary mb-0">{{ description }}</p>
  </div>
</template>
