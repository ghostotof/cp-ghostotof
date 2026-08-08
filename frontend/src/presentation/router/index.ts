import { createRouter, createWebHistory } from 'vue-router'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'
import { i18n } from '../i18n'
import { applySeoMeta } from './seo'
import { LOCALE_STORAGE_KEY, resolvePreferredLocale } from './preferredLocale'
import { authState } from '../../application/auth/useAuth'

declare module 'vue-router' {
  interface RouteMeta {
    /** Clé de message vue-i18n utilisée pour construire `document.title`. */
    titleKey?: string
    /** Clé de message vue-i18n utilisée pour `<meta name="description">`. */
    descriptionKey?: string
  }
}

/**
 * Découpage par route (dynamic import) : chaque page n'est chargée que lorsque
 * l'utilisateur y accède, au lieu d'alourdir le bundle initial avec toutes les pages.
 */
const LandingPage = () => import('../pages/LandingPage.vue')
const AboutPage = () => import('../pages/AboutPage.vue')
const LoginPage = () => import('../pages/LoginPage.vue')
const NotFoundPage = () => import('../pages/NotFoundPage.vue')

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
    { path: '/', redirect: () => `/${resolvePreferredLocale()}` },
    {
      path: '/:locale(fr|en)',
      children: [
        {
          path: '',
          name: 'home',
          component: LandingPage,
          meta: { titleKey: 'seo.home.title', descriptionKey: 'seo.home.description' },
        },
        {
          path: 'about',
          name: 'about',
          component: AboutPage,
          meta: { titleKey: 'seo.about.title', descriptionKey: 'seo.about.description' },
        },
        {
          path: 'login',
          name: 'login',
          component: LoginPage,
          meta: { titleKey: 'seo.login.title', descriptionKey: 'seo.login.description' },
          beforeEnter: (to) => {
            // Déjà connecté : la page de login n'a rien à offrir de plus.
            if (authState.user) {
              return `/${to.params.locale}`
            }
          },
        },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: NotFoundPage,
      meta: { titleKey: 'seo.notFound.title', descriptionKey: 'seo.notFound.description' },
    },
  ],
})

function syncLocale(locale: Locale): void {
  i18n.global.locale.value = locale
  localStorage.setItem(LOCALE_STORAGE_KEY, locale)
  document.documentElement.lang = locale
}

router.beforeEach((to) => {
  const routeLocale = to.params.locale
  if (typeof routeLocale === 'string' && isSupportedLocale(routeLocale)) {
    syncLocale(routeLocale)
    return
  }

  // Route catch-all (404) : elle ne capture pas de paramètre `:locale`, mais l'URL
  // peut malgré tout commencer par un préfixe de langue valide (ex. "/en/oups") —
  // on le respecte pour que la page 404 elle-même reste dans la bonne langue.
  const firstSegment = to.path.split('/')[1]
  if (isSupportedLocale(firstSegment)) {
    syncLocale(firstSegment)
  }
})

router.afterEach((to) => {
  applySeoMeta(to)
})
