<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
};

defineProps<Props>();
</script>

<template>
    <section
        class="flex min-h-48 flex-col items-center justify-center rounded-xl border border-dashed p-8 text-center"
    >
        <h2 class="text-base font-semibold">{{ title }}</h2>
        <p
            v-if="description"
            class="text-muted-foreground mt-1 max-w-md text-sm"
        >
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-4">
            <slot />
        </div>
    </section>
</template>
