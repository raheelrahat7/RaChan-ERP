<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { computed } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

// The display serif only reads well large; small titles use the interface font.
const small = computed(() => /\btext-(xs|sm|base)\b/.test(String(props.class ?? "")))
</script>

<template>
  <h3
    data-slot="card-title"
    :class="cn('font-display text-[22px] leading-tight font-medium', small && 'font-sans font-semibold', props.class)"
  >
    <slot />
  </h3>
</template>
