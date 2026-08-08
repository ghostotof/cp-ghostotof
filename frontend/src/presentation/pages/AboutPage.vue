<script setup lang="ts">
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'

const { aboutContent } = usePortfolioContent()
</script>

<template>
  <section class="container-xl py-5">
    <div class="surface-panel p-3 p-sm-4">
      <p class="d-flex align-items-center gap-2 text-primary text-uppercase small fw-semibold mb-4">
        <span class="rounded-circle bg-primary" style="width: 0.4rem; height: 0.4rem" />
        {{ aboutContent.eyebrow }}
      </p>

      <h1 class="h3 fw-bold text-white" style="max-width: 40rem">
        {{ aboutContent.title }}
      </h1>

      <p class="text-body-secondary mt-3 mb-0" style="max-width: 40rem">
        {{ aboutContent.message }}
      </p>
    </div>
  </section>
</template>
