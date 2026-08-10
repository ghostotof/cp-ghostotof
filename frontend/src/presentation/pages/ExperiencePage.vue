<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'
import { useExperienceTechnologies } from '../../application/experience/useExperienceTechnologies'
import { resolveIcon } from '../ui/icons'

const { t } = useI18n()
const { experienceContent } = usePortfolioContent()
const { technologies, isLoading, hasError } = useExperienceTechnologies()

const maxYears = computed(() => technologies.value.reduce((max, technology) => Math.max(max, technology.years), 0))
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

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('experience.loading') }}
      </p>

      <p
        v-else-if="hasError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('experience.error') }}
      </p>

      <ol
        v-else
        class="list-unstyled d-flex flex-column gap-2 mb-0"
      >
        <li
          v-for="(technology, index) in technologies"
          :key="technology.name"
          class="experience-item d-flex align-items-center gap-3 p-2 p-sm-3"
          :class="{ 'experience-item--top': index < 3 }"
        >
          <span
            class="experience-item__rank fw-semibold flex-shrink-0"
            aria-hidden="true"
          >{{ index + 1 }}</span>
          <span
            class="experience-item__icon flex-shrink-0"
            :class="{ 'experience-item__icon--empty': !resolveIcon(technology.iconKey) }"
          >
            <component
              :is="resolveIcon(technology.iconKey)"
              v-if="resolveIcon(technology.iconKey)"
              width="18"
              height="18"
              aria-hidden="true"
            />
          </span>
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex justify-content-between align-items-baseline gap-2">
              <span class="d-flex align-items-baseline gap-2 flex-wrap">
                <span class="fw-semibold text-white">{{ technology.name }}</span>
                <span
                  v-if="technology.relatedTechnology"
                  class="experience-item__related small text-body-secondary"
                >{{ technology.relatedTechnology.name }}</span>
              </span>
              <span class="small text-body-secondary flex-shrink-0">{{ technology.duration }}</span>
            </div>
            <div
              class="experience-bar mt-2"
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
.experience-item {
  border-radius: 0.75rem;
  border: 1px solid transparent;
  transition:
    background-color 0.2s ease,
    border-color 0.2s ease,
    transform 0.2s ease;
}

.experience-item:hover {
  background: rgba(255, 255, 255, 0.04);
  border-color: var(--bs-border-color);
  transform: translateX(2px);
}

.experience-item--top .experience-item__rank {
  background: linear-gradient(135deg, #7c3aed, #4f46e5);
  border-color: transparent;
  color: #fff;
}

.experience-item__rank {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 50%;
  border: 1px solid var(--bs-border-color);
  background: rgba(255, 255, 255, 0.03);
  color: var(--bs-body-color);
  font-size: 0.8rem;
}

.experience-item__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: 50%;
  background: rgba(124, 58, 237, 0.12);
  color: #c4b5fd;
  transition: transform 0.2s ease;
}

.experience-item:hover .experience-item__icon {
  transform: scale(1.1);
}

.experience-item__icon--empty {
  background: transparent;
}

.experience-item:hover .experience-item__icon--empty {
  transform: none;
}

.experience-bar {
  height: 0.4rem;
  border-radius: 999px;
  background-color: var(--bs-secondary-bg);
  overflow: hidden;
}

.experience-bar__fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #7c3aed, #a78bfa);
  transform-origin: left;
  animation: experience-bar-reveal 0.7s ease-out both;
}

.experience-item__related {
  padding-left: 0.6rem;
  border-left: 2px solid var(--bs-border-color);
  font-weight: 400;
  opacity: 0.75;
}

@keyframes experience-bar-reveal {
  from {
    transform: scaleX(0);
  }
  to {
    transform: scaleX(1);
  }
}

@media (prefers-reduced-motion: reduce) {
  .experience-item,
  .experience-item__icon {
    transition: none;
  }

  .experience-item:hover {
    transform: none;
  }

  .experience-item:hover .experience-item__icon {
    transform: none;
  }

  .experience-bar__fill {
    animation: none;
  }
}
</style>
