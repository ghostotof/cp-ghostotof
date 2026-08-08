<script setup lang="ts">
import characterImg from '../../assets/character.png'
</script>

<template>
  <div class="hero-illustration">
    <span class="hero-illustration__particle" style="top: 18%; left: 10%" />
    <span class="hero-illustration__particle" style="top: 24%; right: 10%; animation-delay: 0.4s" />
    <span class="hero-illustration__particle" style="bottom: 22%; left: 14%; animation-delay: 0.8s" />
    <span class="hero-illustration__particle" style="bottom: 16%; right: 8%; animation-delay: 1.2s" />
    <img :src="characterImg" alt="Illustration : développeur derrière son ordinateur" />
  </div>
</template>
