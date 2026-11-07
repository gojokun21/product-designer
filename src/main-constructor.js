/**
 * Flow B entry — constructor wizard.
 * Mounts ConstructorApp on #pd-constructor-app, seeding it with the
 * PDConstructorData-shaped bootstrap rendered by class-constructor-shortcode.php.
 */
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import ConstructorApp from './apps/ConstructorApp.vue';
import { readConfig } from './lib/readConfig.js';
import './styles/designer.css';
import './styles/constructor.css';

function boot() {
  const el = document.getElementById('pd-constructor-app');
  if (!el) {
    return;
  }
  const config = readConfig(el);
  const app = createApp(ConstructorApp, { config });
  app.use(createPinia());
  app.mount(el);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
