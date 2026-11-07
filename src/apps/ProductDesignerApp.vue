<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import EditorModal from '../components/editor/EditorModal.vue';
import { installCartBridge } from '../bridge/cartBridge.js';
import { useEditorStore } from '../stores/editor.js';

const props = defineProps({
  config: { type: Object, required: true },
});

const editorStore = useEditorStore();
const modalOpen = ref(false);
let bridge = null;

function openModal() {
  modalOpen.value = true;
}

const defaultMockups = () => ({
  front: props.config.product ? props.config.product.mockup_front_url : '',
  back: props.config.product ? props.config.product.mockup_back_url : '',
});

// Swap the editor mockup to the one configured for the chosen variation
// (per-color images set in the admin). Falls back to the product-level mockup.
function applyVariationMockup(variation) {
  const map = (props.config.product && props.config.product.variation_mockups) || {};
  const entry = variation && variation.variation_id ? map[String(variation.variation_id)] : null;
  const def = defaultMockups();
  const front = (entry && entry.mockup_front_url) || def.front;
  const back = (entry && entry.mockup_back_url) || def.back;
  editorStore.swapMockups(front, back);
}

function onOpenClick(e) {
  const btn = e.target.closest && e.target.closest('.pd-open-designer');
  if (btn) {
    e.preventDefault();
    openModal();
  }
}

const i18n = props.config.i18n || {};

// „Față: Mic 10 × 10 cm · Spate: Mare 28 × 40 cm (+100 MDL)" for the saved
// block, plus what the print sizes add, which the theme adds to its total.
// Both come from the server — the same numbers the cart charges.
function sidesInfo(res) {
  let text = res.print_summary || '';
  if (!text) {
    const sides = [];
    if (res.preview_url) {
      sides.push(i18n.sideFront || 'Față');
    }
    if (res.preview_back_url) {
      sides.push(i18n.sideBack || 'Spate');
    }
    text = sides.join(' · ');
  }
  return { sides: text, extra: Number(res.extra_price) || 0 };
}

function onSaved(res) {
  if (bridge) {
    bridge.writeHidden(res, sidesInfo(res));
  }
  editorStore.setSaved(res);
  // Desktop: close shortly after save (matches old UX). Mobile keeps editor open.
  const mobile = typeof matchMedia === 'function' && matchMedia('(max-width: 900px)').matches;
  if (!mobile) {
    setTimeout(() => {
      modalOpen.value = false;
    }, 500);
  }
}

onMounted(() => {
  bridge = installCartBridge(
    {
      onRequireDesign: openModal,
      onVariation: (variation) => applyVariationMockup(variation),
      onVariationReset: () => {
        const def = defaultMockups();
        editorStore.swapMockups(def.front, def.back);
      },
      onClear: () => editorStore.clearSaved(),
    },
    i18n
  );
  document.addEventListener('click', onOpenClick);
});
onBeforeUnmount(() => {
  document.removeEventListener('click', onOpenClick);
});
</script>

<template>
  <EditorModal v-model="modalOpen" :config="config" @saved="onSaved" />
</template>
