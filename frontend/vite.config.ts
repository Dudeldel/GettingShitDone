import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  base: '/',
  build: {
    outDir: 'dist',
  },
  // One config rather than a separate vitest.config.ts: the plugin and resolve setup the
  // app builds with is then the same one the tests run against, so the two cannot drift.
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    restoreMocks: true,
    // Required by the private-window test, which replaces the sessionStorage global
    // outright (vi.spyOn cannot touch it under jsdom — see CaptureForm.test.tsx).
    unstubGlobals: true,
  },
})
