<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Entity = { id: number; name?: string; reference?: string };
const props = defineProps<{
    documents: {
        id: number;
        name: string;
        category: string;
        expires_on: string | null;
        is_expiring: boolean;
    }[];
    properties: Entity[];
    leases: Entity[];
    tenants: Entity[];
    vendors: Entity[];
    canManage: boolean;
}>();
const subjectType = ref('property');
const entities = computed(
    () =>
        ({
            property: props.properties,
            lease: props.leases,
            tenant: props.tenants,
            vendor: props.vendors,
        })[subjectType.value] ?? [],
);
const form = useForm({
    subject_type: 'property',
    subject_id: '',
    category: '',
    expires_on: '',
    file: null as File | null,
});
function upload(): void {
    form.subject_type = subjectType.value;
    form.post('/compliance-documents', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>
<template>
    <Head title="Document compliance" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Document compliance"
            description="Secure documents and expiry alerts for property, leases, tenants, and vendors."
        />
        <Card v-if="canManage"
            ><CardHeader
                ><CardTitle>Upload compliance document</CardTitle></CardHeader
            ><CardContent
                ><form class="flex flex-wrap gap-3" @submit.prevent="upload">
                    <select
                        v-model="subjectType"
                        class="border-input h-9 rounded-md border px-3"
                        @change="form.subject_id = ''"
                    >
                        <option value="property">{{ t('Property') }}</option>
                        <option value="lease">{{ t('Lease') }}</option>
                        <option value="tenant">Tenant</option>
                        <option value="vendor">
                            {{ t('Vendor') }}
                        </option></select
                    ><select
                        v-model="form.subject_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">Record</option>
                        <option
                            v-for="entity in entities"
                            :key="entity.id"
                            :value="String(entity.id)"
                        >
                            {{ entity.name || entity.reference }}
                        </option></select
                    ><Input
                        v-model="form.category"
                        placeholder="Category"
                        required
                    /><Input v-model="form.expires_on" type="date" /><input
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
                </form></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>{{ t('Documents') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p
                    v-if="!documents.length"
                    class="text-muted-foreground text-sm"
                >
                    No compliance documents yet.
                </p>
                <div
                    v-for="document in documents"
                    :key="document.id"
                    class="flex justify-between border-b pb-3 last:border-0"
                >
                    <a
                        :href="`/compliance-documents/${document.id}/download`"
                        class="underline"
                        >{{ document.name }} · {{ document.category }}</a
                    ><span
                        :class="
                            document.is_expiring
                                ? 'text-amber-600'
                                : 'text-muted-foreground'
                        "
                        >{{ document.expires_on || 'No expiry' }}</span
                    >
                </div></CardContent
            ></Card
        >
    </div>
</template>
