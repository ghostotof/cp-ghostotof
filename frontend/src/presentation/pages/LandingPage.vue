<script setup lang="ts">
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'
import HeroSection from '../sections/HeroSection.vue'
import TechnologiesSection from '../sections/TechnologiesSection.vue'
import QualitySection from '../sections/QualitySection.vue'
import StatsSection from '../sections/StatsSection.vue'

const { heroContent, featuredTechnologies, additionalTechnologies, qualityPrinciples, qualityTraits, stats } =
  usePortfolioContent()
</script>

<template>
  <HeroSection :content="heroContent" />
  <TechnologiesSection
    :featured-technologies="featuredTechnologies"
    :additional-technologies="additionalTechnologies"
  />
  <QualitySection
    :quality-principles="qualityPrinciples"
    :quality-traits="qualityTraits"
  />
  <StatsSection :stats="stats" />
</template>