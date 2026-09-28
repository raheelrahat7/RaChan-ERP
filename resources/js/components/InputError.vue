<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';

defineProps<{
    message?: string;
    id?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p
            :id="id"
            role="alert"
            class="text-destructive flex items-center gap-1.5 text-xs"
        >
            <CircleAlert class="size-3.5 shrink-0" aria-hidden="true" />
            {{ message }}
        </p>
    </div>
</template>
