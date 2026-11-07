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
const printSizes = (props.config.product && props.config.product.print_sizes) || null;

// The variation's print size slug ('' when the product has no print sizes).
function printSizeOf(variation) {
  const attrs = (variation && variation.attributes) || {};
  return printSizes ? attrs['attribute_' + printSizes.attribute] || '' : '';
}

function applyVariationPrintSize(variation) {
  const size = printSizeOf(variation);
  if (!size) {
    return;
  }
  editorStore.setPrintSize(size);
  // A design saved for another print size can't be bought with this one
  // (the cart refuses it too): drop it, the editor still holds the objects.
  const saved = editorStore.saved;
  if (saved.design_id && saved.print_size && saved.print_size !== size) {
    editorStore.clearSaved();
    if (bridge) {
      bridge.clearHidden('stale');
    }
  }
}

// „Față + spate (+75 lei)" for the saved block, plus the second-side price the
// theme adds to its total.
function sidesInfo(res) {
  const front = i18n.sideFront || 'Față';
  const back = i18n.sideBack || 'Spate';
  const sides = [];
  if (res.preview_url) {
    sides.push(front);
  }
  if (res.preview_back_url) {
    sides.push(sides.length ? back.toLowerCase() : back);
  }
  const size = printSizes && res.print_size ? printSizes.sizes[res.print_size] : null;
  const extra = res.preview_url && res.preview_back_url && size ? Number(size.second_side_price) || 0 : 0;
  let text = sides.join(' + ');
  if (extra && size.second_side_price_text) {
    text += ' (+' + size.second_side_price_text + ')';
  }
  return { sides: text, extra };
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
      onVariation: (variation) => {
        applyVariationMockup(variation);
        applyVariationPrintSize(variation);
      },
      onVariationReset: () => {
        const def = defaultMockups();
        editorStore.swapMockups(def.front, def.back);
      },
      onClear: () => editorStore.clearSaved(),
    },
    i18n
  );
  // The print size already selected (default attributes): found_variation
  // may have fired before the app mounted.
  if (printSizes) {
    const select = document.querySelector('form.variations_form [name="attribute_' + printSizes.attribute + '"]');
    if (select && select.value) {
      editorStore.setPrintSize(select.value);
    }
  }
  document.addEventListener('click', onOpenClick);
});
onBeforeUnmount(() => {
  document.removeEventListener('click', onOpenClick);
});
</script>

<template>
  <EditorModal v-model="modalOpen" :config="config" @saved="onSaved" />
</template>
