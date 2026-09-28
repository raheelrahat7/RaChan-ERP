<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
};

defineProps<Props>();
</script>

<template>
    <section
        class="flex min-h-48 flex-col items-center justify-center px-6 py-11 text-center"
    >
        <div
            v-if="$slots.icon"
            class="border-input text-accent-text mb-4 grid size-14 place-items-center rounded-full border [&_svg]:size-5"
        >
            <slot name="icon" />
        </div>
        <h2 class="font-display text-2xl font-medium">{{ title }}</h2>
        <p
            v-if="description"
            class="text-muted-foreground mx-auto mt-1.5 max-w-sm text-sm"
        >
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-5">
            <slot />
        </div>
    </section>
</template>
