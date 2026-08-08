<script setup lang="ts">
import type { Stat } from '../../domain/portfolio/entities/Stat'
import { resolveIcon } from '../ui/icons'

defineProps<{
  stats: readonly Stat[]
}>()
</script>

<template>
  <section class="container-xl pb-5">
    <div class="surface-panel p-3 p-sm-4">
      <div class="row row-cols-2 row-cols-sm-4 g-4">
        <div
          v-for="stat in stats"
          :key="stat.label"
          class="col d-flex align-items-center gap-3"
        >
          <component
            :is="resolveIcon(stat.iconKey)"
            width="24"
            height="24"
            class="text-primary flex-shrink-0"
            aria-hidden="true"
          />
          <div>
            <p class="h5 fw-bold text-white mb-0">
              {{ stat.value }}
            </p>
            <p class="small text-body-secondary mb-0">
              {{ stat.label }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
