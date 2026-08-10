// L'URL de l'API est résolue au RUNTIME (window.__APP_CONFIG__, injecté par
// docker/node/docker-entrypoint.sh au démarrage du conteneur), et non au build
// Vite : cela permet de construire une seule image frontend, promue telle
// quelle de la préprod vers la prod (comme pour le backend). Le fallback sur
// VITE_API_URL couvre `npm run dev` / le conteneur Vite dev, qui ne servent
// jamais config.js.
export function getApiUrl(): string {
  return window.__APP_CONFIG__?.apiUrl ?? import.meta.env.VITE_API_URL
}
