import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import { router } from './presentation/router'
import { i18n } from './presentation/i18n'
import { PORTFOLIO_CONTENT_REPOSITORY } from './application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from './infrastructure/portfolio/StaticPortfolioContentRepository'
import { AUTH_REPOSITORY, useAuth } from './application/auth/useAuth'
import { HttpAuthRepository } from './infrastructure/auth/HttpAuthRepository'
import { CV_REPOSITORY } from './application/cv/useCvDownload'
import { HttpCvRepository } from './infrastructure/cv/HttpCvRepository'
import { EXPERIENCE_TECHNOLOGY_REPOSITORY } from './application/experience/useExperienceTechnologies'
import { HttpExperienceTechnologyRepository } from './infrastructure/experience/HttpExperienceTechnologyRepository'
import { CONTACT_REPOSITORY } from './application/contact/useContactForm'
import { HttpContactRepository } from './infrastructure/contact/HttpContactRepository'
import { ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY } from './application/admin/technologies/useAdminExperienceTechnologies'
import { HttpAdminExperienceTechnologyRepository } from './infrastructure/admin/technologies/HttpAdminExperienceTechnologyRepository'
import { ABOUT_CONTENT_REPOSITORY } from './application/about/useAboutContent'
import { HttpAboutContentRepository } from './infrastructure/about/HttpAboutContentRepository'
import { QUALITY_CONTENT_REPOSITORY } from './application/quality/useQualityContent'
import { HttpQualityContentRepository } from './infrastructure/quality/HttpQualityContentRepository'
import { CONTRIBUTION_REPOSITORY } from './application/contributions/useContributions'
import { ADMIN_CONTRIBUTION_REPOSITORY } from './application/admin/contributions/useAdminContributions'
import { HttpAdminContributionRepository } from './infrastructure/admin/contributions/HttpAdminContributionRepository'
import { ADMIN_INCIDENT_REPOSITORY } from './application/admin/incidents/useAdminIncidents'
import { HttpAdminIncidentRepository } from './infrastructure/admin/incidents/HttpAdminIncidentRepository'
import { ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY } from './application/admin/anonymousCv/useAdminAnonymousCvSections'
import { HttpAdminAnonymousCvSectionRepository } from './infrastructure/admin/anonymousCv/HttpAdminAnonymousCvSectionRepository'
import { HttpContributionRepository } from './infrastructure/contributions/HttpContributionRepository'
import { INCIDENT_REPOSITORY } from './application/incidents/useIncidents'
import { WATCH_REPOSITORY } from './application/watch/useWatch'
import { HttpWatchRepository } from './infrastructure/watch/HttpWatchRepository'
import { ADMIN_WATCHED_PRODUCT_REPOSITORY } from './application/admin/watch/useAdminWatchedProducts'
import { HttpAdminWatchedProductRepository } from './infrastructure/admin/watch/HttpAdminWatchedProductRepository'
import { ADMIN_VULNERABILITY_REPOSITORY } from './application/admin/watch/useAdminVulnerabilities'
import { HttpAdminVulnerabilityRepository } from './infrastructure/admin/watch/HttpAdminVulnerabilityRepository'
import { HttpIncidentRepository } from './infrastructure/incidents/HttpIncidentRepository'
import { ADMIN_ABOUT_SETTINGS_REPOSITORY } from './application/admin/about/useAdminAboutSettings'
import { HttpAdminAboutSettingsRepository } from './infrastructure/admin/about/HttpAdminAboutSettingsRepository'
import { ADMIN_ABOUT_SITE_CARD_REPOSITORY } from './application/admin/about/useAdminAboutSiteCards'
import { HttpAdminAboutSiteCardRepository } from './infrastructure/admin/about/HttpAdminAboutSiteCardRepository'
import { ADMIN_ABOUT_ME_CARD_REPOSITORY } from './application/admin/about/useAdminAboutMeCards'
import { HttpAdminAboutMeCardRepository } from './infrastructure/admin/about/HttpAdminAboutMeCardRepository'
import { ADMIN_QUALITY_PRINCIPLE_REPOSITORY } from './application/admin/quality/useAdminQualityPrinciples'
import { HttpAdminQualityPrincipleRepository } from './infrastructure/admin/quality/HttpAdminQualityPrincipleRepository'
import { ADMIN_QUALITY_TRAIT_REPOSITORY } from './application/admin/quality/useAdminQualityTraits'
import { HttpAdminQualityTraitRepository } from './infrastructure/admin/quality/HttpAdminQualityTraitRepository'
import { ADMIN_USER_REPOSITORY } from './application/admin/users/useAdminUsers'
import { HttpAdminUserRepository } from './infrastructure/admin/users/HttpAdminUserRepository'
import { ACCOUNT_REPOSITORY } from './application/account/useAccountPasswordSetup'
import { HttpAccountRepository } from './infrastructure/account/HttpAccountRepository'
import { CASE_STUDY_REPOSITORY } from './application/caseStudies/useCaseStudies'
import { HttpCaseStudyRepository } from './infrastructure/caseStudies/HttpCaseStudyRepository'
import { ANONYMOUS_CV_REPOSITORY } from './application/anonymousCv/useAnonymousCv'
import { HttpAnonymousCvRepository } from './infrastructure/anonymousCv/HttpAnonymousCvRepository'
import { BASE_ACCESS_REPOSITORY } from './application/baseAccess/useBaseAccess'
import { HttpBaseAccessRepository } from './infrastructure/baseAccess/HttpBaseAccessRepository'
import { getApiUrl } from './infrastructure/config/getApiUrl'

const app = createApp(App)
const apiUrl = getApiUrl()

// Composition root : c'est le seul endroit où une implémentation concrète du
// repository est instanciée et reliée à son abstraction (DIP).
app.provide(PORTFOLIO_CONTENT_REPOSITORY, new StaticPortfolioContentRepository())
app.provide(AUTH_REPOSITORY, new HttpAuthRepository(apiUrl))
app.provide(CV_REPOSITORY, new HttpCvRepository(apiUrl))
app.provide(EXPERIENCE_TECHNOLOGY_REPOSITORY, new HttpExperienceTechnologyRepository(apiUrl))
app.provide(CONTACT_REPOSITORY, new HttpContactRepository(apiUrl))
app.provide(ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY, new HttpAdminExperienceTechnologyRepository(apiUrl))
app.provide(ABOUT_CONTENT_REPOSITORY, new HttpAboutContentRepository(apiUrl))
app.provide(QUALITY_CONTENT_REPOSITORY, new HttpQualityContentRepository(apiUrl))
app.provide(CONTRIBUTION_REPOSITORY, new HttpContributionRepository(apiUrl))
app.provide(CASE_STUDY_REPOSITORY, new HttpCaseStudyRepository(apiUrl))
app.provide(ANONYMOUS_CV_REPOSITORY, new HttpAnonymousCvRepository(apiUrl))
app.provide(BASE_ACCESS_REPOSITORY, new HttpBaseAccessRepository(apiUrl))
app.provide(INCIDENT_REPOSITORY, new HttpIncidentRepository(apiUrl))
app.provide(WATCH_REPOSITORY, new HttpWatchRepository(apiUrl))
app.provide(ADMIN_WATCHED_PRODUCT_REPOSITORY, new HttpAdminWatchedProductRepository(apiUrl))
app.provide(ADMIN_VULNERABILITY_REPOSITORY, new HttpAdminVulnerabilityRepository(apiUrl))
app.provide(ADMIN_CONTRIBUTION_REPOSITORY, new HttpAdminContributionRepository(apiUrl))
app.provide(ADMIN_INCIDENT_REPOSITORY, new HttpAdminIncidentRepository(apiUrl))
app.provide(ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY, new HttpAdminAnonymousCvSectionRepository(apiUrl))
app.provide(ADMIN_ABOUT_SETTINGS_REPOSITORY, new HttpAdminAboutSettingsRepository(apiUrl))
app.provide(ADMIN_ABOUT_SITE_CARD_REPOSITORY, new HttpAdminAboutSiteCardRepository(apiUrl))
app.provide(ADMIN_ABOUT_ME_CARD_REPOSITORY, new HttpAdminAboutMeCardRepository(apiUrl))
app.provide(ADMIN_QUALITY_PRINCIPLE_REPOSITORY, new HttpAdminQualityPrincipleRepository(apiUrl))
app.provide(ADMIN_QUALITY_TRAIT_REPOSITORY, new HttpAdminQualityTraitRepository(apiUrl))
app.provide(ADMIN_USER_REPOSITORY, new HttpAdminUserRepository(apiUrl))
app.provide(ACCOUNT_REPOSITORY, new HttpAccountRepository(apiUrl))

app.use(i18n)
app.use(router)

app.mount('#app')

// Le cookie httpOnly du JWT n'est pas lisible en JS : on interroge /api/me pour
// savoir si un rechargement de page correspond toujours à une session valide.
app.runWithContext(() => useAuth().checkAuth())

console.log(
  "%c🎸 It's a long way to the top (if you wanna rock the source code).",
  'color:#f59e0b;font-weight:600;',
)
