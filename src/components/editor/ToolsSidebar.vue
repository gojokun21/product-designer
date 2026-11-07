<script setup>
import { inject, ref, computed } from 'vue';
import TextControls from './TextControls.vue';
import PdIcon from './PdIcon.vue';

const fabric = inject('pdFabric');
const config = inject('pdConfig');
const api = inject('pdApi');

defineProps({
  textSheetOpen: { type: Boolean, default: false },
});
const emit = defineEmits(['close-text-sheet']);

const i18n = computed(() => config.value.i18n || {});
const limits = computed(() => config.value.limits || { maxUploadMB: 5, mimeTypes: [] });
const uploadInput = ref(null);
const dragOver = ref(false);

// „PNG, JPG, WEBP · max 5 MB" — from the same limits the upload enforces.
const uploadHint = computed(() => {
  const types = (limits.value.mimeTypes || [])
    .map((m) => String(m).split('/')[1] || '')
    .map((ext) => (ext === 'jpeg' ? 'JPG' : ext.toUpperCase()))
    .filter((ext, i, all) => ext && all.indexOf(ext) === i);
  const max = limits.value.maxUploadMB ? 'max ' + limits.value.maxUploadMB + ' MB' : '';
  return [types.join(', '), max].filter(Boolean).join(' · ');
});

const selectionTitle = computed(() =>
  fabric.state.selectionIsText ? i18n.value.textTitle || 'Proprietăți text' : i18n.value.selectionTitle || 'Selecție'
);

async function uploadFile(file) {
  if (!file) {
    return;
  }
  const maxBytes = limits.value.maxUploadMB * 1024 * 1024;
  if (file.size > maxBytes) {
    fabric.say(i18n.value.tooLarge || 'Fișier prea mare.');
    return;
  }
  if (limits.value.mimeTypes.indexOf(file.type) === -1) {
    fabric.say(i18n.value.badType || 'Tip neacceptat.');
    return;
  }
  fabric.say(i18n.value.uploading || 'Se încarcă...');
  try {
    const res = await api.uploadImage(file);
    fabric.addUploadedImage(res.url);
  } catch (err) {
    fabric.say('Eroare upload: ' + (err.message || err));
  }
}

function onUploadChange(e) {
  const file = e.target.files && e.target.files[0];
  e.target.value = '';
  uploadFile(file);
}

// The upload tile also takes a file dropped on it.
function onDrop(e) {
  dragOver.value = false;
  uploadFile(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]);
}

function openFilePicker() {
  if (uploadInput.value) {
    uploadInput.value.click();
  }
}

defineExpose({ openFilePicker });
</script>

<template>
  <aside class="pd-tools">
    <section class="pd-panel-section">
      <h3 class="pd-panel-title">{{ i18n.addTitle || 'Adaugă' }}</h3>
      <div class="pd-add-grid">
        <button type="button" class="pd-add-tile pd-add-text" @click="fabric.addText()">
          <PdIcon name="text" :size="22" />
          <span>{{ i18n.addText || 'Adaugă text' }}</span>
        </button>
        <label
          class="pd-add-tile pd-upload-btn"
          :class="{ 'is-dragover': dragOver }"
          tabindex="0"
          role="button"
          @keydown.enter.prevent="openFilePicker"
          @keydown.space.prevent="openFilePicker"
          @dragover.prevent="dragOver = true"
          @dragleave="dragOver = false"
          @drop.prevent="onDrop"
        >
          <PdIcon name="image" :size="22" />
          <span>{{ i18n.uploadImg || 'Încarcă imagine' }}</span>
          <input
            ref="uploadInput"
            type="file"
            class="pd-upload-input"
            accept="image/png,image/jpeg,image/webp"
            hidden
            @change="onUploadChange"
          />
        </label>
      </div>
      <p v-if="uploadHint" class="pd-panel-hint">{{ uploadHint }}</p>
    </section>

    <div v-if="fabric.state.hasSelection" class="pd-selection-head">
      <h3 class="pd-panel-title">{{ selectionTitle }}</h3>
      <button
        type="button"
        class="pd-icon-btn pd-delete"
        :title="i18n.delete || 'Șterge selecția'"
        :aria-label="i18n.delete || 'Șterge selecția'"
        @click="fabric.deleteSelected()"
      >
        <PdIcon name="trash" :size="18" />
      </button>
    </div>
    <div v-else class="pd-panel-empty">
      <PdIcon name="pointer" :size="18" />
      <p>{{ i18n.selectHint || 'Selectează un text sau o imagine de pe produs ca să le editezi aici.' }}</p>
    </div>

    <!-- Desktop: the panel's properties. Mobile: a bottom sheet, which only works
         as a direct child of .pd-tools (display: contents there). -->
    <TextControls :sheet-open="textSheetOpen" @close="emit('close-text-sheet')" />
  </aside>
</template>
