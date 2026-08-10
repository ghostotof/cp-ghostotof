/// <reference types="vite/client" />
/// <reference types="unplugin-icons/types/vue" />

// Injecté au démarrage du conteneur par docker/node/docker-entrypoint.sh
// (envsubst dans config.js), absent en dev (npm run dev). Voir
// src/infrastructure/config/getApiUrl.ts.
interface Window {
  __APP_CONFIG__?: {
    apiUrl: string
  }
}
