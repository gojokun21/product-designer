<script setup>
/**
 * Line icons for the editor chrome — one stroke weight (1.75) and one 24px grid,
 * so every button reads as part of the same set.
 */
defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 20 },
});

const PATHS = {
  text: 'M5 7V5h14v2M12 5v14M9 19h6',
  image:
    'M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8.5 8a1.5 1.5 0 1 0 0 3 1.5 1.5 0 1 0 0-3zM21 15l-5-5L5 20',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3',
  alignLeft: 'M4 6h16M4 10h10M4 14h16M4 18h10',
  alignCenter: 'M4 6h16M7 10h10M4 14h16M7 18h10',
  alignRight: 'M4 6h16M10 10h10M4 14h16M10 18h10',
  minus: 'M5 12h14',
  plus: 'M12 5v14M5 12h14',
  back: 'M19 12H5M12 19l-7-7 7-7',
  close: 'M18 6 6 18M6 6l12 12',
  swap: 'M7 7l-3 3 3 3M17 17l3-3-3-3M4 10h12M20 14H8',
  cart: 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2 5h13M9 18.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 1 0 0-3zM18 18.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 1 0 0-3z',
  pointer: 'M5 3l6.5 17 2.4-7.1L21 10.5z',
  fit: 'M3 9V5a2 2 0 0 1 2-2h4M15 3h4a2 2 0 0 1 2 2v4M21 15v4a2 2 0 0 1-2 2h-4M9 21H5a2 2 0 0 1-2-2v-4',
};
</script>

<template>
  <svg
    class="pd-icon"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.75"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <path :d="PATHS[name]" />
  </svg>
</template>
