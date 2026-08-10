<script setup lang="ts">
import type { HeroContent } from '../../domain/portfolio/entities/HeroContent'
import BaseButton from '../ui/BaseButton.vue'
import IconIllustration from '../ui/IconIllustration.vue'
import { resolveIcon } from '../ui/icons'

defineProps<{
  content: HeroContent
}>()
</script>

<template>
  <section
    id="hero"
    class="container-xl py-5 py-lg-6"
  >
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <p
          class="text-eyebrow text-uppercase small fw-semibold mb-3"
          style="letter-spacing: 0.08em"
        >
          {{ content.eyebrow }}
        </p>

        <h1 class="display-5 fw-bold text-white">
          {{ content.titleLead }}
          <span class="text-gradient d-block">{{ content.titleAccent }}</span>
        </h1>

        <p
          class="text-body-secondary mt-4"
          style="max-width: 34rem"
        >
          {{ content.description }}
        </p>

        <div class="d-flex flex-wrap gap-3 mt-4">
          <BaseButton
            v-for="cta in content.callsToAction"
            :key="cta.label"
            :href="cta.href"
            :variant="cta.variant"
            :icon-key="cta.iconKey"
          >
            {{ cta.label }}
          </BaseButton>
        </div>

        <ul class="list-unstyled d-flex flex-wrap gap-4 mt-5 mb-0 small text-body-secondary">
          <li
            v-for="highlight in content.highlights"
            :key="highlight.label"
            class="d-flex align-items-center gap-2"
          >
            <component
              :is="resolveIcon(highlight.iconKey)"
              width="16"
              height="16"
              class="text-primary"
              aria-hidden="true"
            />
            {{ highlight.label }}
          </li>
        </ul>
      </div>

      <div class="col-lg-6 d-flex justify-content-center justify-content-lg-end">
        <IconIllustration />
      </div>
    </div>
  </section>
</template>
