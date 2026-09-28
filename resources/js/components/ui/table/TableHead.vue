<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <th
    data-slot="table-head"
    :class="cn('text-muted-foreground h-11 px-3.5 text-start align-middle text-[10px] font-medium tracking-[0.16em] whitespace-nowrap uppercase first:ps-5 last:pe-5 [&:has([role=checkbox])]:w-10 [&:has([role=checkbox])]:pe-0', props.class)"
  >
    <slot />
  </th>
</template>
