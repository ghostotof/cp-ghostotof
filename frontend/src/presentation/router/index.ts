import { createRouter, createWebHistory, type RouteLocationNormalized } from 'vue-router'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'
import { i18n } from '../i18n'
import { applySeoMeta } from './seo'
import { LOCALE_STORAGE_KEY, resolvePreferredLocale } from './preferredLocale'
import { authState, waitForAuthCheck } from '../../application/auth/useAuth'
import { hasRole } from '../../domain/auth/services/hasRole'

declare module 'vue-router' {
  interface RouteMeta {
    /** Clé de message vue-i18n utilisée pour construire `document.title`. */
    titleKey?: string
    /** Clé de message vue-i18n utilisée pour `<meta name="description">`. */
    descriptionKey?: string
    /** Route réservée aux utilisateurs authentifiés (ex. espace /admin). */
    requiresAuth?: boolean
    /** Rôle(s) requis en plus de requiresAuth (ex. ['ROLE_SUPER']) ; au moins un doit matcher. */
    roles?: readonly string[]
  }
}

/**
 * Découpage par route (dynamic import) : chaque page n'est chargée que lorsque
 * l'utilisateur y accède, au lieu d'alourdir le bundle initial avec toutes les pages.
 */
const LandingPage = () => import('../pages/LandingPage.vue')
const AboutPage = () => import('../pages/AboutPage.vue')
const ExperiencePage = () => import('../pages/ExperiencePage.vue')
const ContactPage = () => import('../pages/ContactPage.vue')
const LoginPage = () => import('../pages/LoginPage.vue')
const LegalNoticePage = () => import('../pages/LegalNoticePage.vue')
const PrivacyPolicyPage = () => import('../pages/PrivacyPolicyPage.vue')
const NotFoundPage = () => import('../pages/NotFoundPage.vue')
const ForbiddenPage = () => import('../pages/ForbiddenPage.vue')
const AdminLayout = () => import('../layout/AdminLayout.vue')
const AdminTechnologiesPage = () => import('../pages/admin/AdminTechnologiesPage.vue')
const AdminAboutPage = () => import('../pages/admin/AdminAboutPage.vue')
const AdminQualityPage = () => import('../pages/admin/AdminQualityPage.vue')
const AdminStatsPage = () => import('../pages/admin/AdminStatsPage.vue')
const AdminUsersPage = () => import('../pages/admin/AdminUsersPage.vue')

/** Rôle requis pour accéder à l'espace /admin (cf. backend CpgUser::ROLE_SUPER). */
const ROLE_SUPER = 'ROLE_SUPER'

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
          path: 'experience',
          name: 'experience',
          component: ExperiencePage,
          meta: { titleKey: 'seo.experience.title', descriptionKey: 'seo.experience.description' },
        },
        {
          path: 'contact',
          name: 'contact',
          component: ContactPage,
          meta: { titleKey: 'seo.contact.title', descriptionKey: 'seo.contact.description' },
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
        {
          path: 'legal-notice',
          name: 'legal-notice',
          component: LegalNoticePage,
          meta: { titleKey: 'seo.legalNotice.title', descriptionKey: 'seo.legalNotice.description' },
        },
        {
          path: 'privacy-policy',
          name: 'privacy-policy',
          component: PrivacyPolicyPage,
          meta: { titleKey: 'seo.privacyPolicy.title', descriptionKey: 'seo.privacyPolicy.description' },
        },
        {
          path: 'forbidden',
          name: 'forbidden',
          component: ForbiddenPage,
          meta: { titleKey: 'seo.forbidden.title', descriptionKey: 'seo.forbidden.description' },
        },
        {
          path: 'admin',
          component: AdminLayout,
          meta: { requiresAuth: true, roles: [ROLE_SUPER] },
          children: [
            { path: '', redirect: { name: 'admin-technologies' } },
            {
              path: 'technologies',
              name: 'admin-technologies',
              component: AdminTechnologiesPage,
              meta: { titleKey: 'seo.adminTechnologies.title', descriptionKey: 'seo.adminTechnologies.description' },
            },
            {
              path: 'about',
              name: 'admin-about',
              component: AdminAboutPage,
              meta: { titleKey: 'seo.adminAbout.title', descriptionKey: 'seo.adminAbout.description' },
            },
            {
              path: 'quality',
              name: 'admin-quality',
              component: AdminQualityPage,
              meta: { titleKey: 'seo.adminQuality.title', descriptionKey: 'seo.adminQuality.description' },
            },
            {
              path: 'stats',
              name: 'admin-stats',
              component: AdminStatsPage,
              meta: { titleKey: 'seo.adminStats.title', descriptionKey: 'seo.adminStats.description' },
            },
            {
              path: 'users',
              name: 'admin-users',
              component: AdminUsersPage,
              meta: { titleKey: 'seo.adminUsers.title', descriptionKey: 'seo.adminUsers.description' },
            },
          ],
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

/**
 * Locale à utiliser pour construire une redirection depuis un garde : les
 * routes protégées (/admin/*) sont toutes nichées sous `/:locale(fr|en)`, le
 * paramètre est donc garanti présent ici.
 */
function guardLocale(to: RouteLocationNormalized): Locale {
  const routeLocale = to.params.locale
  return typeof routeLocale === 'string' && isSupportedLocale(routeLocale) ? routeLocale : resolvePreferredLocale()
}

router.beforeEach(async (to) => {
  if (!to.meta.requiresAuth) {
    return
  }

  // Rechargement de page / navigation directe : le checkAuth() lancé par
  // main.ts peut ne pas avoir résolu quand ce garde s'exécute (cf. le
  // docblock de waitForAuthCheck).
  await waitForAuthCheck()

  if (!authState.user) {
    return { name: 'login', params: { locale: guardLocale(to) }, query: { redirect: to.fullPath } }
  }

  const requiredRoles = to.meta.roles
  if (requiredRoles && !requiredRoles.some((role) => hasRole(authState.user, role))) {
    return { name: 'forbidden', params: { locale: guardLocale(to) } }
  }
})

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
