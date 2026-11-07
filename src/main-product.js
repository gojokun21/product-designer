/**
 * Flow A entry — single-product modal editor.
 * Mounts ProductDesignerApp on #pd-designer-app, seeding it with the PDData-shaped
 * config rendered by class-frontend.php in `data-pd-config`.
 */
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import ProductDesignerApp from './apps/ProductDesignerApp.vue';
import { readConfig } from './lib/readConfig.js';
import './styles/designer.css';

function boot() {
  const el = document.getElementById('pd-designer-app');
  if (!el) {
    return;
  }
  const config = readConfig(el);
  const app = createApp(ProductDesignerApp, { config });
  app.use(createPinia());
  app.mount(el);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
