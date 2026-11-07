<script setup>
import { inject, ref, computed, nextTick } from 'vue';
import PdIcon from './PdIcon.vue';

const fabric = inject('pdFabric');

// Sheet open state (mobile) is owned by EditorShell and passed down.
defineProps({
  sheetOpen: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const t = fabric.state.text;
const textareaEl = ref(null);

// The slider covers the useful range; the number input keeps the full 8–600.
const SLIDER_MAX = 300;
const sliderSize = computed(() => Math.min(SLIDER_MAX, Math.max(8, t.fontSize || 48)));
// Filled part of the slider track (WebKit has no ::range-progress).
const sliderPct = computed(() => ((sliderSize.value - 8) / (SLIDER_MAX - 8)) * 100 + '%');

const FONTS = [
  'Arial',
  'Helvetica',
  'Times New Roman',
  'Georgia',
  'Courier New',
  'Verdana',
  'Tahoma',
  'Trebuchet MS',
  'Impact',
  'Comic Sans MS',
];

const ALIGNS = [
  { value: 'left', icon: 'alignLeft', label: 'Stânga' },
  { value: 'center', icon: 'alignCenter', label: 'Centru' },
  { value: 'right', icon: 'alignRight', label: 'Dreapta' },
];

// Print-friendly basics; anything else goes through the custom picker.
// 11 + the picker = a 6 × 2 grid.
const PALETTE = [
  '#111111',
  '#ffffff',
  '#9e9e9e',
  '#e53935',
  '#ec407a',
  '#fb8c00',
  '#fdd835',
  '#43a047',
  '#1e88e5',
  '#1a237e',
  '#8e24aa',
];
const fillLower = computed(() => String(t.fill || '').toLowerCase());
const customFill = computed(() => PALETTE.indexOf(fillLower.value) === -1);

// On mobile "Adaugă text" the composable asks us to focus the textarea.
fabric.on('focusTextValue', () => {
  nextTick(() => {
    if (textareaEl.value) {
      textareaEl.value.focus();
      textareaEl.value.select();
    }
  });
});
</script>

<template>
  <div
    class="pd-text-controls"
    :class="{ 'is-open': sheetOpen }"
    :hidden="!fabric.state.selectionIsText && !sheetOpen"
  >
    <div class="pd-text-controls__head">
      <div class="pd-text-controls__title">Proprietăți text</div>
      <button type="button" class="pd-text-controls__done" @click="emit('close')">Gata</button>
    </div>

    <label class="pd-field">
      <span class="pd-field__label">Text</span>
      <textarea
        ref="textareaEl"
        class="pd-input pd-text-value"
        rows="2"
        placeholder="Scrie textul…"
        :value="t.value"
        @input="fabric.setText($event.target.value)"
      ></textarea>
    </label>

    <label class="pd-field">
      <span class="pd-field__label">Font</span>
      <select class="pd-input pd-text-font" :value="t.fontFamily" @change="fabric.setFontFamily($event.target.value)">
        <option v-for="f in FONTS" :key="f" :value="f">{{ f }}</option>
      </select>
    </label>

    <div class="pd-field">
      <div class="pd-field__row">
        <span class="pd-field__label">Mărime</span>
        <div class="pd-stepper">
          <button type="button" class="pd-stepper__btn" aria-label="Micșorează textul" @click="fabric.nudgeFontSize(-1)">
            <PdIcon name="minus" :size="16" />
          </button>
          <input
            type="number"
            class="pd-stepper__value pd-text-size"
            min="8"
            max="600"
            step="1"
            aria-label="Mărime text (px)"
            :value="t.fontSize"
            @input="fabric.setFontSize($event.target.value)"
          />
          <button type="button" class="pd-stepper__btn" aria-label="Mărește textul" @click="fabric.nudgeFontSize(1)">
            <PdIcon name="plus" :size="16" />
          </button>
        </div>
      </div>
      <input
        type="range"
        class="pd-range pd-text-size-range"
        min="8"
        :max="SLIDER_MAX"
        step="1"
        aria-label="Mărime text"
        :style="{ '--pd-range-pct': sliderPct }"
        :value="sliderSize"
        @input="fabric.setFontSize($event.target.value)"
      />
    </div>

    <div class="pd-field">
      <span class="pd-field__label">Stil</span>
      <div class="pd-field__row">
        <div class="pd-segmented" role="group" aria-label="Stil text">
          <button
            type="button"
            class="pd-seg-btn pd-text-bold"
            :class="{ 'is-active': t.bold }"
            :aria-pressed="t.bold ? 'true' : 'false'"
            title="Bold"
            @click="fabric.toggleBold()"
          >
            <strong>B</strong>
          </button>
          <button
            type="button"
            class="pd-seg-btn pd-text-italic"
            :class="{ 'is-active': t.italic }"
            :aria-pressed="t.italic ? 'true' : 'false'"
            title="Italic"
            @click="fabric.toggleItalic()"
          >
            <em>I</em>
          </button>
          <button
            type="button"
            class="pd-seg-btn pd-text-underline"
            :class="{ 'is-active': t.underline }"
            :aria-pressed="t.underline ? 'true' : 'false'"
            title="Underline"
            @click="fabric.toggleUnderline()"
          >
            <u>U</u>
          </button>
        </div>
        <div class="pd-segmented" role="group" aria-label="Aliniere">
          <button
            v-for="a in ALIGNS"
            :key="a.value"
            type="button"
            class="pd-seg-btn"
            :class="{ 'is-active': t.textAlign === a.value }"
            :aria-pressed="t.textAlign === a.value ? 'true' : 'false'"
            :title="a.label"
            :aria-label="a.label"
            @click="fabric.setTextAlign(a.value)"
          >
            <PdIcon :name="a.icon" :size="18" />
          </button>
        </div>
      </div>
    </div>

    <div class="pd-field">
      <span class="pd-field__label">Culoare</span>
      <div class="pd-color-row">
        <button
          v-for="c in PALETTE"
          :key="c"
          type="button"
          class="pd-color-dot"
          :class="{ 'is-active': fillLower === c }"
          :style="{ background: c }"
          :aria-label="c"
          :aria-pressed="fillLower === c ? 'true' : 'false'"
          @click="fabric.setFill(c)"
        ></button>
        <!-- Rainbow until a colour outside the palette is picked, then that colour. -->
        <label
          class="pd-color-dot pd-color-dot--custom"
          :class="{ 'is-active': customFill }"
          :style="customFill ? { background: t.fill } : null"
          title="Altă culoare"
        >
          <input type="color" class="pd-text-color" aria-label="Altă culoare" :value="t.fill" @input="fabric.setFill($event.target.value)" />
        </label>
      </div>
    </div>
  </div>
</template>
