<script setup lang="ts">
import { computed } from 'vue'
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'
import { resolveIcon } from '../ui/icons'

const { experienceContent } = usePortfolioContent()

const maxYears = computed(() =>
  experienceContent.value.technologies.reduce((max, technology) => Math.max(max, technology.years), 0),
)
</script>

<template>
  <section class="container-xl py-5 d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h1 class="d-flex align-items-center gap-2 text-eyebrow text-uppercase small fw-semibold mb-3">
        <span
          class="rounded-circle bg-primary"
          style="width: 0.4rem; height: 0.4rem"
          aria-hidden="true"
        />
        {{ experienceContent.eyebrow }}
      </h1>

      <p class="text-body-secondary mb-4">
        {{ experienceContent.description }}
      </p>

      <ol class="list-unstyled d-flex flex-column gap-3 mb-0">
        <li
          v-for="(technology, index) in experienceContent.technologies"
          :key="technology.name"
          class="d-flex align-items-center gap-3"
        >
          <span
            class="fw-semibold text-body-secondary text-end"
            style="width: 1.5rem"
            aria-hidden="true"
          >{{ index + 1 }}</span>
          <component
            :is="resolveIcon(technology.iconKey)"
            v-if="resolveIcon(technology.iconKey)"
            width="20"
            height="20"
            class="text-primary flex-shrink-0"
            aria-hidden="true"
          />
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex justify-content-between align-items-baseline gap-2">
              <span class="fw-semibold text-white">{{ technology.name }}</span>
              <span class="small text-body-secondary flex-shrink-0">{{ technology.duration }}</span>
            </div>
            <div
              class="experience-bar mt-1"
              aria-hidden="true"
            >
              <div
                class="experience-bar__fill"
                :style="{ width: `${(technology.years / maxYears) * 100}%` }"
              />
            </div>
          </div>
        </li>
      </ol>
    </div>
  </section>
</template>

<style scoped>
.experience-bar {
  height: 0.35rem;
  border-radius: 999px;
  background-color: var(--bs-secondary-bg);
  overflow: hidden;
}

.experience-bar__fill {
  height: 100%;
  border-radius: 999px;
  background-color: var(--bs-primary);
}
</style>
