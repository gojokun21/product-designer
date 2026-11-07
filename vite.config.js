import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

/**
 * Vite build for the Product Designer plugin.
 *
 * Two entry points share one editor:
 *   - product:     Flow A (single-product modal) — mounts on #pd-designer-app
 *   - constructor: Flow B (wizard shortcode)     — mounts on #pd-constructor-app
 *
 * Output goes to assets/dist/ with a manifest the PHP `Vite` helper reads to
 * enqueue the hashed JS/CSS. `base: ''` keeps asset URLs relative; PHP builds
 * the absolute URL from PD_PLUGIN_URL + the manifest path.
 */
export default defineConfig({
  plugins: [vue()],
  base: '',
  build: {
    outDir: 'assets/dist',
    manifest: true,
    emptyOutDir: true,
    rollupOptions: {
      input: {
        product: resolve(__dirname, 'src/main-product.js'),
        constructor: resolve(__dirname, 'src/main-constructor.js'),
      },
    },
  },
  server: {
    cors: true,
    strictPort: true,
    port: 5173,
  },
});
