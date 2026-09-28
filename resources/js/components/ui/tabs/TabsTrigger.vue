<script setup lang="ts">
import type { TabsTriggerProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TabsTrigger, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TabsTriggerProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <TabsTrigger
    data-slot="tabs-trigger"
    :class="cn(
      'text-muted-foreground data-[state=active]:text-foreground focus-visible:ring-ring relative -mb-px inline-flex items-center gap-1.5 pb-3 text-[13px] font-medium whitespace-nowrap transition-colors outline-none after:absolute after:inset-x-0 after:-bottom-px after:h-0.5 after:bg-transparent focus-visible:rounded-sm focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:after:bg-primary dark:data-[state=active]:after:bg-ring [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4',
      props.class,
    )"
    v-bind="forwardedProps"
  >
    <slot />
  </TabsTrigger>
</template>
