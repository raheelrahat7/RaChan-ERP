<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useLocale } from '@/composables/useLocale';
const { locale, t } = useLocale();
function change(event: Event): void {
    router.post(
        '/locale',
        { locale: (event.target as HTMLSelectElement).value },
        { preserveState: true },
    );
}
</script>
<template>
    <label class="inline-flex items-center gap-2 p-2 text-sm"
        ><span>{{ t('Language') }}</span
        ><select
            :value="locale"
            class="bg-background rounded border p-1"
            @change="change"
        >
            <option value="en" lang="en">English</option>
            <option value="ar" lang="ar">العربية</option>
        </select></label
    >
</template>
