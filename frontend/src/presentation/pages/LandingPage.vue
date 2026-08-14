<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'
import { useQualityContent } from '../../application/quality/useQualityContent'
import { useStats } from '../../application/stats/useStats'
import HeroSection from '../sections/HeroSection.vue'
import TechnologiesSection from '../sections/TechnologiesSection.vue'
import QualitySection from '../sections/QualitySection.vue'
import StatsSection from '../sections/StatsSection.vue'

const { t } = useI18n()
const { heroContent, featuredTechnologies, additionalTechnologies } = usePortfolioContent()
const { principles: qualityPrinciples, traits: qualityTraits, isLoading: isQualityLoading, hasError: hasQualityError } = useQualityContent()
const { stats, isLoading: isStatsLoading, hasError: hasStatsError } = useStats()
</script>

<template>
  <HeroSection :content="heroContent" />
  <TechnologiesSection
    :featured-technologies="featuredTechnologies"
    :additional-technologies="additionalTechnologies"
  />

  <section
    v-if="isQualityLoading"
    class="container-xl py-4"
  >
    <p class="text-body-secondary mb-0">
      {{ t('quality.loading') }}
    </p>
  </section>
  <section
    v-else-if="hasQualityError"
    class="container-xl py-4"
  >
    <p
      class="text-danger mb-0"
      role="alert"
    >
      {{ t('quality.error') }}
    </p>
  </section>
  <QualitySection
    v-else
    :quality-principles="qualityPrinciples"
    :quality-traits="qualityTraits"
  />

  <section
    v-if="isStatsLoading"
    class="container-xl pb-5"
  >
    <p class="text-body-secondary mb-0">
      {{ t('stats.loading') }}
    </p>
  </section>
  <section
    v-else-if="hasStatsError"
    class="container-xl pb-5"
  >
    <p
      class="text-danger mb-0"
      role="alert"
    >
      {{ t('stats.error') }}
    </p>
  </section>
  <StatsSection
    v-else
    :stats="stats"
  />
</template>