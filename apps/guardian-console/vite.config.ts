import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

// The dev server runs in Docker behind the Caddy gateway, which is the only
// thing published on the host. Browsers load the app from the gateway's single
// origin (the same one that serves /api, ADR 0016), and HMR websockets must
// connect back through that same port.
const gatewayPort = Number(process.env.FLOW_GATEWAY_PORT ?? 18080)

export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    // The production policy (ADR 0026) states the build loads no `data:` URIs, and font-src is
    // 'self' with no data: source. Vite base64-inlines any asset under 4KB by default, which is a
    // real trap for fonts: the smallest subset files (cyrillic-ext, unused by this Latin-script
    // interface but still part of the packages' shipped CSS) land under that threshold and would
    // otherwise be inlined into the stylesheet as data: URIs — invisible in a diff, and a real font
    // request that CSP would then have no directive to allow. Disabling inlining entirely keeps
    // every font (and any future small asset) a real hashed file under font-src 'self', with nothing
    // depending on staying just above a byte threshold.
    assetsInlineLimit: 0,
  },
  server: {
    host: true, // listen on all interfaces inside the container
    port: 5173,
    strictPort: true,
    allowedHosts: ['commons.flowlife.localhost'],
    hmr: { clientPort: gatewayPort },
    watch: {
      // Native filesystem events by default. Polling is a fallback for setups
      // where events do not propagate (see docs/development/docker.md).
      usePolling: process.env.FLOW_VITE_POLLING === 'true',
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    css: false,
  },
})
