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
