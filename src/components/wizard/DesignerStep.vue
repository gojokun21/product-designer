<script setup>
import { ref, computed, watch, provide, onBeforeUnmount } from 'vue';
import EditorShell from '../editor/EditorShell.vue';
import SummarySidebar from './SummarySidebar.vue';
import PdIcon from '../editor/PdIcon.vue';
import { useWizardStore } from '../../stores/wizard.js';
import { lockBodyScroll, unlockBodyScroll } from '../../composables/useBodyScrollLock.js';

const wizard = useWizardStore();
const shellRef = ref(null);
const adding = ref(false);

const i18n = computed(() => wizard.i18n);
const backTarget = computed(() => (wizard.productVariable ? 2.5 : 2));
const backLabel = computed(() =>
  wizard.productVariable
    ? i18n.value.changeOptions || 'Schimbă opțiunile'
    : i18n.value.changeProduct || 'Schimbă produsul'
);
const active = computed(() => wizard.step === 3);

// A colour swatch in the editor changes the variation that gets ordered.
provide('pdPickColor', (o) => wizard.chooseColorByMockup(o.front_url));

// Back from the options with another variation of the same product: keep the
// design, switch the mockup and the print frame.
watch(
  () => wizard.designerConfig,
  (cfg, prev) => {
    if (!cfg || !prev || !shellRef.value || cfg.product.id !== prev.product.id) {
      return;
    }
    const fabric = shellRef.value.fabric;
    fabric.swapMockups(cfg.product.mockup_front_url, cfg.product.mockup_back_url);
    fabric.setPrintSize(cfg.product.print_size);
  }
);

function onSave() {
  if (shellRef.value) {
    shellRef.value.save();
  }
}
function onSaved(res) {
  wizard.applySaved(res);
}
// The cart gets what is on the canvas now: save, then add — edits made after
// an earlier save are not lost.
async function onAddToCart() {
  if (!shellRef.value || adding.value) {
    return;
  }
  adding.value = true;
  const res = await shellRef.value.save();
  if (res) {
    wizard.addToCart();
  } else {
    adding.value = false;
  }
}

// Step 3 on mobile becomes a full-screen app — lock page scroll while it shows.
// The step stays mounted behind the options step, so follow the step.
let locked = false;
function setLocked(on) {
  if (on === locked) {
    return;
  }
  locked = on;
  if (on) {
    lockBodyScroll();
  } else {
    unlockBodyScroll();
  }
}
watch(active, setLocked, { immediate: true });
onBeforeUnmount(() => setLocked(false));
</script>

<template>
  <section class="pd-constructor__panel" data-panel="3" aria-label="Personalizează produsul">
    <!-- Slim app bar of the full-screen editor (CSS shows it at step 3). -->
    <header class="pd-step3-bar">
      <button type="button" class="pd-step3-bar__back" aria-label="Înapoi" :title="backLabel" @click="wizard.goBack(backTarget)">
        <PdIcon name="back" :size="20" />
      </button>
      <span class="pd-step3-bar__title">{{ wizard.productName }}</span>
      <button type="button" class="pd-step3-bar__save pd-save" @click="onSave">Salvează</button>
    </header>

    <div class="pd-breadcrumb">
      <button type="button" class="pd-breadcrumb__back" @click="wizard.goBack(backTarget)">
        <span aria-hidden="true">&larr;</span> {{ backLabel }}
      </button>
      <span class="pd-breadcrumb__current">{{ wizard.productName }}</span>
    </div>

    <p v-if="wizard.busy && !wizard.designerConfig" class="pd-loading">{{ i18n.loading || 'Se încarcă...' }}</p>

    <EditorShell
      v-if="wizard.designerConfig"
      :key="wizard.productId"
      ref="shellRef"
      :config="wizard.designerConfig"
      :active="active"
      layout-class="pd-designer-layout"
      :show-summary="true"
      @saved="onSaved"
    >
      <template #summary="{ open }">
        <SummarySidebar :open="open" :adding="adding" @save="onSave" @add-to-cart="onAddToCart" />
      </template>
    </EditorShell>
  </section>
</template>
