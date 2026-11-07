<script setup>
import { inject } from 'vue';

/**
 * Mobile-only strip that appears above the bottom bar when a text object is
 * selected. It keeps the canvas — and the object's scale handles — visible,
 * which the full-height sheet does not. The sheet is one tap away ("Editează").
 */
const fabric = inject('pdFabric');

defineProps({
  sheetOpen: { type: Boolean, default: false },
});
const emit = defineEmits(['edit']);
</script>

<template>
  <div class="pd-text-quickbar" :hidden="!fabric.state.selectionIsText || sheetOpen">
    <button
      type="button"
      class="pd-qb-btn pd-qb-minus"
      aria-label="Micșorează textul"
      @click="fabric.nudgeFontSize(-1)"
    >
      A<span class="pd-qb-sign">−</span>
    </button>
    <span class="pd-qb-size">{{ fabric.state.text.fontSize }}px</span>
    <button type="button" class="pd-qb-btn pd-qb-plus" aria-label="Mărește textul" @click="fabric.nudgeFontSize(1)">
      A<span class="pd-qb-sign">+</span>
    </button>
    <label class="pd-qb-color" aria-label="Culoare text">
      <input type="color" :value="fabric.state.text.fill" @input="fabric.setFill($event.target.value)" />
    </label>
    <button type="button" class="pd-qb-edit" @click="emit('edit')">Editează</button>
  </div>
</template>
