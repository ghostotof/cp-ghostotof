<script setup lang="ts">
import type { AboutContent } from '../../domain/portfolio/entities/AboutContent'
import BaseCard from '../ui/BaseCard.vue'

defineProps<{
  content: AboutContent
}>()
</script>

<template>
  <section id="a-propos" class="container-xl py-4">
    <div class="surface-panel p-3 p-sm-4">
      <p class="d-flex align-items-center gap-2 text-primary text-uppercase small fw-semibold mb-4">
        <span class="rounded-circle bg-primary" style="width: 0.4rem; height: 0.4rem" />
        {{ content.eyebrow }}
      </p>

      <h2 class="h3 fw-bold text-white" style="max-width: 40rem">
        {{ content.titleLead }}
        <span class="text-gradient d-block">{{ content.titleAccent }}</span>
      </h2>

      <div class="row g-4 mt-1">
        <div class="col-lg-6">
          <p v-for="paragraph in content.paragraphs" :key="paragraph" class="text-body-secondary">
            {{ paragraph }}
          </p>
        </div>

        <div class="col-lg-6">
          <div class="row row-cols-1 row-cols-sm-2 g-3">
            <div v-for="value in content.values" :key="value.title" class="col">
              <BaseCard :title="value.title" :description="value.description" :icon-key="value.iconKey" />
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
