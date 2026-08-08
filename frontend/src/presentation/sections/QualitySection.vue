<script setup lang="ts">
import type { QualityPrinciple } from '../../domain/portfolio/entities/QualityPrinciple'
import type { QualityTrait } from '../../domain/portfolio/entities/QualityTrait'
import BaseCard from '../ui/BaseCard.vue'
import BaseBadge from '../ui/BaseBadge.vue'

defineProps<{
  qualityPrinciples: readonly QualityPrinciple[]
  qualityTraits: readonly QualityTrait[]
}>()
</script>

<template>
  <section class="container-xl py-4">
    <div class="surface-panel p-3 p-sm-4">
      <p class="d-flex align-items-center gap-2 text-primary text-uppercase small fw-semibold mb-4">
        <span class="rounded-circle bg-primary" style="width: 0.4rem; height: 0.4rem" />
        Principes &amp; qualité logicielle
      </p>

      <div class="row row-cols-1 row-cols-sm-3 g-3">
        <div v-for="principle in qualityPrinciples" :key="principle.title" class="col">
          <BaseCard :title="principle.title" :description="principle.description" :icon-key="principle.iconKey" />
        </div>
      </div>

      <div class="d-flex flex-wrap gap-3 border-top mt-4 pt-4">
        <BaseBadge v-for="trait in qualityTraits" :key="trait.label" icon-key="check">
          {{ trait.label }}
        </BaseBadge>
      </div>
    </div>
  </section>
</template>
