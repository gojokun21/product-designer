<script setup>
import { ref, watch, computed, onBeforeUnmount } from 'vue';
import EditorShell from './EditorShell.vue';
import PdIcon from './PdIcon.vue';
import { lockBodyScroll, unlockBodyScroll } from '../../composables/useBodyScrollLock.js';

const props = defineProps({
  config: { type: Object, required: true },
  modelValue: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'saved']);

const shellRef = ref(null);
const i18n = computed(() => props.config.i18n || {});

let locked = false;
watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      document.documentElement.classList.add('pd-modal-open');
      if (!locked) {
        lockBodyScroll();
        locked = true;
      }
    } else {
      document.documentElement.classList.remove('pd-modal-open');
      if (locked) {
        unlockBodyScroll();
        locked = false;
      }
      if (shellRef.value) {
        shellRef.value.closeAllSheets();
      }
    }
  }
);

function close() {
  emit('update:modelValue', false);
}
function onSave() {
  if (shellRef.value) {
    shellRef.value.save();
  }
}
function onSaved(res) {
  emit('saved', res);
}
function onKeydown(e) {
  if (e.key === 'Escape' && props.modelValue) {
    const c = shellRef.value && shellRef.value.fabric;
    const active = c && c.state && c.state.selectionIsText;
    // Don't close while inline-editing text.
    if (!active) {
      close();
    }
  }
}
document.addEventListener('keydown', onKeydown);
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown);
  if (locked) {
    unlockBodyScroll();
  }
});
</script>

<template>
  <Teleport to="body">
    <div class="pd-modal" :hidden="!modelValue" :aria-hidden="modelValue ? 'false' : 'true'" role="dialog" aria-modal="true">
      <div class="pd-modal__backdrop" @click="close"></div>
      <div class="pd-modal__dialog" role="document">
        <header class="pd-modal__header">
          <button type="button" class="pd-modal__close" aria-label="Închide" @click="close">
            <PdIcon name="close" :size="20" />
          </button>
          <h2>Editor design</h2>
          <button type="button" class="pd-modal__save-top pd-save" @click="onSave">Salvează</button>
        </header>

        <EditorShell
          ref="shellRef"
          :config="config"
          :active="modelValue"
          layout-class="pd-modal__body"
          :show-summary="false"
          @saved="onSaved"
        />

        <footer class="pd-modal__footer">
          <button type="button" class="button pd-cancel" @click="close">{{ i18n.cancel || 'Anulează' }}</button>
          <button type="button" class="button button-primary pd-save" @click="onSave">
            {{ i18n.save || 'Salvează designul' }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
