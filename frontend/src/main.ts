import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import { router } from './presentation/router'
import { i18n } from './presentation/i18n'
import { PORTFOLIO_CONTENT_REPOSITORY } from './application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from './infrastructure/portfolio/StaticPortfolioContentRepository'

const app = createApp(App)

// Composition root : c'est le seul endroit où une implémentation concrète du
// repository est instanciée et reliée à son abstraction (DIP).
app.provide(PORTFOLIO_CONTENT_REPOSITORY, new StaticPortfolioContentRepository())

app.use(i18n)
app.use(router)

app.mount('#app')
