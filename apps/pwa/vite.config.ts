import { defineConfig } from 'vite';

// In development the PWA calls /api on its own origin and Vite forwards it to the
// Laravel API, so no CORS setup is needed. VITE_API_URL points a build elsewhere.
const target = process.env.API_PROXY_TARGET ?? 'http://localhost:8000';

export default defineConfig({
  server: { port: 5173, host: true, proxy: { '/api': { target, changeOrigin: true } } },
  preview: { port: 4173, host: true, proxy: { '/api': { target, changeOrigin: true } } },
});
