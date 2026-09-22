/// <reference types="vite/client" />
/// <reference types="unplugin-icons/types/vue" />

// Fixée au build de l'image (docker/node/Dockerfile, ARG APP_VERSION), absente
// en dev. Voir src/infrastructure/config/getAppVersion.ts.
interface ImportMetaEnv {
  readonly VITE_APP_VERSION?: string
}

// Injecté au démarrage du conteneur par docker/node/docker-entrypoint.sh
// (envsubst dans config.js), absent en dev (npm run dev). Voir
// src/infrastructure/config/getApiUrl.ts.
interface Window {
  __APP_CONFIG__?: {
    apiUrl: string
  }
}
