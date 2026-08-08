import { createRouter, createWebHistory } from 'vue-router'

/**
 * Découpage par route (dynamic import) : chaque page n'est chargée que lorsque
 * l'utilisateur y accède, au lieu d'alourdir le bundle initial avec toutes les pages.
 */
const LandingPage = () => import('../pages/LandingPage.vue')
const AboutPage = () => import('../pages/AboutPage.vue')

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior(to, _from, savedPosition) {
    if (savedPosition) {
      return savedPosition
    }
    if (to.hash) {
      return { el: to.hash, behavior: 'smooth' }
    }
    return { top: 0 }
  },
  routes: [
    { path: '/', name: 'home', component: LandingPage },
    { path: '/a-propos', name: 'about', component: AboutPage },
  ],
})
