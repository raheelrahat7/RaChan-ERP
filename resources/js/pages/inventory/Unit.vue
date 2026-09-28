<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
const props = defineProps<{
    unit: {
        id: number;
        number: string;
        type: string;
        status: string;
        property: { id: number; name: string } | null;
        building: { id: number; name: string } | null;
    };
    documents: { id: number; name: string }[];
    canManageInventory: boolean;
}>();
const form = useForm({ file: null as File | null });
function upload(): void {
    form.post(`/inventory/units/${props.unit.id}/documents`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>
<template>
    <Head :title="`Unit ${unit.number}`" />
    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            :translate-text="false"
            :title="`Unit ${unit.number}`"
            :description="`${unit.property?.name || 'Property'} · ${unit.building?.name || 'Standalone'} · ${unit.status}`"
        /><Card
            ><CardHeader
                ><CardTitle>{{ t('Documents') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><form
                    v-if="canManageInventory"
                    class="flex gap-3"
                    @submit.prevent="upload"
                >
                    <input
                        type="file"
                        required
                        @change="
                            form.file =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] || null
                        "
                    /><Button :disabled="form.processing">{{
                        t('Upload')
                    }}</Button>
                </form>
                <p
                    v-if="!documents.length"
                    class="text-muted-foreground text-sm"
                >
                    No documents yet.
                </p>
                <a
                    v-for="document in documents"
                    :key="document.id"
                    :href="`/documents/${document.id}/versions`"
                    class="block text-sm underline"
                    >{{ document.name }}</a
                ></CardContent
            ></Card
        >
    </div>
</template>
