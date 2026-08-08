/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import Icons from 'unplugin-icons/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    Icons({
      autoInstall: false,
      compiler: 'vue3',
    }),
  ],
  test: {
    environment: 'jsdom',
    include: ['src/**/*.spec.ts'],
  },
})
