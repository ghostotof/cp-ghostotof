<script setup lang="ts">
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'
import AppHeader from '../layout/AppHeader.vue'
import HeroSection from '../sections/HeroSection.vue'
import TechnologiesSection from '../sections/TechnologiesSection.vue'
import QualitySection from '../sections/QualitySection.vue'
import StatsSection from '../sections/StatsSection.vue'

const {
  siteIdentity,
  navigationLinks,
  heroContent,
  featuredTechnologies,
  additionalTechnologies,
  qualityPrinciples,
  qualityTraits,
  stats,
} = usePortfolioContent()
</script>

<template>
  <div class="min-vh-100">
    <AppHeader :site-identity="siteIdentity" :navigation-links="navigationLinks" />
    <main>
      <HeroSection :content="heroContent" />
      <TechnologiesSection
        :featured-technologies="featuredTechnologies"
        :additional-technologies="additionalTechnologies"
      />
      <QualitySection :quality-principles="qualityPrinciples" :quality-traits="qualityTraits" />
      <StatsSection :stats="stats" />
    </main>
  </div>
</template>
