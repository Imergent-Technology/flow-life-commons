/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Origin of the platform API, e.g. http://api.flowlife.localhost:18080 (no trailing slash). */
  readonly VITE_API_BASE_URL: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
