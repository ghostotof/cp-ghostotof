<script setup lang="ts">
import { computed } from 'vue'
import { resolveIcon } from './icons'

const props = withDefaults(
  defineProps<{
    title: string
    description?: string
    iconKey?: string
    highlighted?: boolean
    /** Niveau de titre HTML du nom de carte, à adapter à la hiérarchie de la page appelante. */
    headingLevel?: 2 | 3
  }>(),
  {
    headingLevel: 3,
  },
)

const icon = computed(() => resolveIcon(props.iconKey))
const headingTag = computed(() => `h${props.headingLevel}`)
</script>

<template>
  <div
    class="card-portfolio p-3 p-sm-4"
    :class="{ 'card-portfolio--highlighted': highlighted }"
  >
    <div class="d-flex align-items-center gap-2 mb-2">
      <component
        :is="icon"
        v-if="icon"
        width="24"
        height="24"
        class="text-primary flex-shrink-0"
        aria-hidden="true"
      />
      <component
        :is="headingTag"
        class="h6 mb-0 text-white"
      >
        {{ title }}
      </component>
    </div>
    <p
      v-if="description"
      class="small text-body-secondary mb-0"
    >
      {{ description }}
    </p>
  </div>
</template>
