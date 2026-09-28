<script setup lang="ts">
import type { DialogRootEmits, DialogRootProps } from "reka-ui"
import { useForwardPropsEmits } from "reka-ui"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useLocale } from "@/composables/useLocale"
import Command from "./Command.vue"

const props = withDefaults(defineProps<DialogRootProps & {
  title?: string
  description?: string
}>(), {
  title: "Command Palette",
  description: "Search for a command to run...",
})
const emits = defineEmits<DialogRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
const { t } = useLocale()
</script>

<template>
  <Dialog v-slot="slotProps" v-bind="forwarded">
    <DialogContent class="overflow-hidden p-0 ">
      <DialogHeader class="sr-only">
        <DialogTitle>{{ t(title) }}</DialogTitle>
        <DialogDescription>{{ t(description) }}</DialogDescription>
      </DialogHeader>
      <Command>
        <slot v-bind="slotProps" />
      </Command>
    </DialogContent>
  </Dialog>
</template>
