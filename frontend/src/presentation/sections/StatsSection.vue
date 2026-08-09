<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { Stat } from '../../domain/portfolio/entities/Stat'
import { resolveIcon } from '../ui/icons'
import IconDisc3 from '~icons/lucide/disc-3'

defineProps<{
  stats: readonly Stat[]
}>()

const { t } = useI18n()
</script>

<template>
  <section class="container-xl pb-5">
    <div class="surface-panel p-3 p-sm-4 position-relative">
      <!-- Titre visuellement masqué : la section n'a pas de libellé visible
           dans la maquette, mais reste repérable par la navigation par titres
           des lecteurs d'écran. -->
      <h2 class="visually-hidden">
        {{ t('stats.sectionTitle') }}
      </h2>

      <!-- Easter egg discret, clin d'œil à "We Are the Champions" (Queen) sur
           une section qui affiche justement les réussites du profil. -->
      <span
        class="position-absolute top-0 end-0 m-2 opacity-25"
        aria-hidden="true"
        title="We Are the Champions"
      >
        <IconDisc3
          width="14"
          height="14"
        />
      </span>

      <div class="row row-cols-2 row-cols-sm-4 g-4">
        <div
          v-for="stat in stats"
          :key="stat.label"
          class="col d-flex align-items-center gap-3 stat-item p-2"
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
