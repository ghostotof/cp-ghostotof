<script setup lang="ts">
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'

const { privacyPolicyContent } = usePortfolioContent()
</script>

<template>
  <section class="container-xl py-5 d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <p class="d-flex align-items-center gap-2 text-eyebrow text-uppercase small fw-semibold mb-3">
        <span
          class="rounded-circle bg-primary"
          style="width: 0.4rem; height: 0.4rem"
          aria-hidden="true"
        />
        {{ privacyPolicyContent.eyebrow }}
      </p>
      <h1 class="h3 fw-bold text-white mb-1">
        {{ privacyPolicyContent.title }}
      </h1>
      <p class="text-body-secondary small mb-4">
        {{ privacyPolicyContent.lastUpdated }}
      </p>

      <div
        v-for="(section, index) in privacyPolicyContent.sections"
        :key="section.heading"
        :class="{ 'border-top mt-4 pt-4': index > 0 }"
      >
        <h2 class="h6 text-body-secondary text-uppercase mb-3">
          {{ section.heading }}
        </h2>
        <p
          v-for="(paragraph, paragraphIndex) in section.paragraphs"
          :key="paragraphIndex"
          class="text-body-secondary mb-2"
        >
          {{ paragraph }}
        </p>
        <ul
          v-if="section.list?.length"
          class="text-body-secondary mb-2"
        >
          <li
            v-for="(item, itemIndex) in section.list"
            :key="itemIndex"
          >
            {{ item }}
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>
