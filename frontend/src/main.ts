import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import { router } from './presentation/router'
import { i18n } from './presentation/i18n'
import { PORTFOLIO_CONTENT_REPOSITORY } from './application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from './infrastructure/portfolio/StaticPortfolioContentRepository'
import { AUTH_REPOSITORY, useAuth } from './application/auth/useAuth'
import { HttpAuthRepository } from './infrastructure/auth/HttpAuthRepository'

const app = createApp(App)

// Composition root : c'est le seul endroit où une implémentation concrète du
// repository est instanciée et reliée à son abstraction (DIP).
app.provide(PORTFOLIO_CONTENT_REPOSITORY, new StaticPortfolioContentRepository())
app.provide(AUTH_REPOSITORY, new HttpAuthRepository(import.meta.env.VITE_API_URL))

app.use(i18n)
app.use(router)

app.mount('#app')

// Le cookie httpOnly du JWT n'est pas lisible en JS : on interroge /api/me pour
// savoir si un rechargement de page correspond toujours à une session valide.
app.runWithContext(() => useAuth().checkAuth())
