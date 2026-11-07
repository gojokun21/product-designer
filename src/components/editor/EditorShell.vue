<script setup>
import { ref, reactive, computed, provide, watch, onMounted, onBeforeUnmount } from 'vue';
import ToolsSidebar from './ToolsSidebar.vue';
import CanvasStage from './CanvasStage.vue';
import BottomBar from './BottomBar.vue';
import TextQuickBar from './TextQuickBar.vue';
import { useFabric } from '../../composables/useFabric.js';
import { useDesignApi } from '../../composables/useDesignApi.js';
import { installViewportVh } from '../../composables/useViewportVh.js';
import { useEditorStore } from '../../stores/editor.js';

const props = defineProps({
  config: { type: Object, required: true },
  active: { type: Boolean, default: true },
  layoutClass: { type: String, default: 'pd-designer-layout' },
  showSummary: { type: Boolean, default: false },
});
const emit = defineEmits(['saved', 'save-error']);

const fabric = useFabric();
const api = useDesignApi(props.config.rest || {});
const editorStore = useEditorStore();

const configRef = computed(() => props.config);
provide('pdFabric', fabric);
provide('pdConfig', configRef);
provide('pdApi', api);

const toolsRef = ref(null);
const stageReady = ref(false);
let booted = false;

const sheets = reactive({ text: false, summary: false });

const i18n = computed(() => props.config.i18n || {});

// ---- sheet management (mobile) ----
function openSheet(name) {
  // Fără slotul de sumar n-ar exista ce deschide — dar `pd-sheet-open` ar bloca
  // totuși scroll-ul de fundal. Nu deschide un sheet care nu e randat.
  if (name === 'summary' && !props.showSummary) {
    return;
  }
  sheets.text = name === 'text';
  sheets.summary = name === 'summary';
  document.documentElement.classList.add('pd-sheet-open');
  fabric.scheduleRefit();
}
function closeSheet(name) {
  sheets[name] = false;
  if (!sheets.text && !sheets.summary) {
    document.documentElement.classList.remove('pd-sheet-open');
  }
  fabric.scheduleRefit();
}
function closeAllSheets() {
  sheets.text = false;
  sheets.summary = false;
  document.documentElement.classList.remove('pd-sheet-open');
}
function toggleSheet(name) {
  if (sheets[name]) {
    closeSheet(name);
  } else {
    openSheet(name);
  }
}

// ---- boot lifecycle ----
function tryBoot() {
  if (!stageReady.value || !props.active) {
    return;
  }
  if (!booted) {
    booted = true;
    // If a variation/color was already chosen before opening, boot directly with
    // that mockup so we don't load the default then immediately swap (which races).
    const cm = editorStore.currentMockups;
    let cfg = props.config;
    if (cm.front || cm.back) {
      cfg = {
        ...props.config,
        product: {
          ...props.config.product,
          mockup_front_url: cm.front || props.config.product.mockup_front_url,
          mockup_back_url: cm.back || props.config.product.mockup_back_url,
        },
      };
    }
    fabric.boot(cfg);
  } else {
    editorStore.applyCurrentMockups();
    fabric.aggressiveRefit();
    fabric.scheduleRefit();
  }
}
function onStageReady() {
  stageReady.value = true;
  tryBoot();
}
watch(
  () => props.active,
  (active) => {
    if (!active) {
      // A sheet left open would keep `pd-sheet-open` on <html> while hidden.
      closeAllSheets();
      return;
    }
    tryBoot();
  }
);

// ---- fabric events → sheet behavior ----
// Selecting text does NOT open the sheet: it would cover the canvas and hide the
// object's scale handles. The quick bar handles the common case (size, color);
// the sheet only opens on demand, or for a freshly added text that needs typing.
fabric.on('requestTextSheet', () => {
  if (fabric.isMobile()) {
    openSheet('text');
  }
});
fabric.on('selectionCleared', () => {
  if (fabric.isMobile()) {
    closeSheet('text');
  }
});
fabric.on('textEditEnter', () => closeAllSheets());

// The quick bar takes a grid row when it appears — the canvas must re-fit.
watch(() => fabric.state.selectionIsText, () => fabric.scheduleRefit());

function onWrapClick() {
  if (sheets.summary) {
    closeSheet('summary');
  }
}

// ---- save ----
// Resolves to the REST response, or null when nothing was saved.
async function save() {
  let s;
  try {
    s = fabric.serialize();
  } catch (e) {
    return null; // tainted canvas — fabric.say already reported it
  }
  if (s.totalObjects === 0) {
    fabric.say(i18n.value.empty || 'Adaugă cel puțin un element în design.');
    return null;
  }
  // Every side starts at a default print size, so `s.print` always carries one
  // per designed side; the server re-checks and prices it.
  fabric.say(i18n.value.saving || 'Se salvează...');
  try {
    const payload = {
      product_id: props.config.product.id,
      design: s.design,
      preview: s.preview,
    };
    if (s.print) {
      payload.print = s.print;
    }
    const res = await api.saveDesign(payload);
    fabric.say(i18n.value.savedOk || 'Design salvat.');
    if (props.showSummary && fabric.isMobile()) {
      setTimeout(() => openSheet('summary'), 300);
    }
    emit('saved', res);
    return res;
  } catch (e) {
    fabric.say('Eroare salvare: ' + (e.message || e));
    emit('save-error', e);
    return null;
  }
}

// ---- bottom-bar actions ----
function onAddImage() {
  if (toolsRef.value && toolsRef.value.openFilePicker) {
    toolsRef.value.openFilePicker();
  }
}
function onToggleSide() {
  if (fabric.state.availableSides.length < 2) {
    return;
  }
  fabric.setActiveSide(fabric.state.activeSide === 'front' ? 'back' : 'front');
}

// ---- global wiring ----
let uninstallVh = null;
function onWinResize() {
  fabric.handleViewportResize();
}
function onKeydown(e) {
  if (!props.active) {
    return;
  }
  const c = fabric.state; // selection mirror
  if ((e.key === 'Delete' || e.key === 'Backspace') && c.hasSelection && !c.selectionIsText) {
    fabric.deleteSelected();
    e.preventDefault();
  }
}

onMounted(() => {
  editorStore.registerFabric(fabric);
  uninstallVh = installViewportVh(() => fabric.scheduleRefit());
  window.addEventListener('resize', onWinResize);
  window.addEventListener('orientationchange', onWinResize);
  document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
  editorStore.registerFabric(null);
  if (uninstallVh) {
    uninstallVh();
  }
  window.removeEventListener('resize', onWinResize);
  window.removeEventListener('orientationchange', onWinResize);
  document.removeEventListener('keydown', onKeydown);
  closeAllSheets();
  fabric.dispose();
});

defineExpose({ save, openSheet, closeSheet, closeAllSheets, fabric });
</script>

<template>
  <div :class="layoutClass">
    <ToolsSidebar ref="toolsRef" :text-sheet-open="sheets.text" @close-text-sheet="closeSheet('text')" />
    <CanvasStage @ready="onStageReady" @wrap-click="onWrapClick" />
    <slot name="summary" :open="sheets.summary" />
    <TextQuickBar :sheet-open="sheets.text" @edit="openSheet('text')" />
    <BottomBar
      :show-summary="showSummary"
      @add-image="onAddImage"
      @toggle-sheet="toggleSheet"
      @toggle-side="onToggleSide"
    />
  </div>
</template>
