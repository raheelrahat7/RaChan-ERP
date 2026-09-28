<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
const props = defineProps<{
    property: { id: number; name: string; type: string; city: string | null };
    buildings: {
        id: number;
        name: string;
        units: { id: number; number: string; type: string; status: string }[];
    }[];
    units: { id: number; number: string; type: string; status: string }[];
    documents: { id: number; name: string; mime_type: string; size: number }[];
    owners: {
        id: number;
        name: string;
        email: string | null;
        phone: string | null;
        pivot: { ownership_share: string };
    }[];
    availableOwners: { id: number; name: string }[];
    canManageInventory: boolean;
}>();
const form = useForm({ file: null as File | null });
const ownerForm = useForm({ owner_id: '', ownership_share: '' });
function upload(): void {
    form.post(`/inventory/properties/${props.property.id}/documents`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => form.reset(),
    });
}
function assignOwner(): void {
    ownerForm.put(`/inventory/properties/${props.property.id}/owners`, {
        preserveScroll: true,
        onSuccess: () => ownerForm.reset(),
    });
}
</script>
<template>
    <Head :title="property.name" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            :translate-text="false"
            :title="property.name"
            :description="`${property.type} · ${property.city || 'No city set'}`"
        />
        <Card>
            <CardHeader><CardTitle>Ownership</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <form
                    v-if="canManageInventory"
                    class="flex flex-wrap gap-3"
                    @submit.prevent="assignOwner"
                >
                    <select
                        v-model="ownerForm.owner_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Owner') }}</option>
                        <option
                            v-for="owner in availableOwners"
                            :key="owner.id"
                            :value="String(owner.id)"
                        >
                            {{ owner.name }}
                        </option>
                    </select>
                    <Input
                        v-model="ownerForm.ownership_share"
                        type="number"
                        min="0.01"
                        max="100"
                        step="0.01"
                        placeholder="Ownership %"
                        required
                    />
                    <Button :disabled="ownerForm.processing"
                        >Assign owner</Button
                    >
                </form>
                <p v-if="!owners.length" class="text-muted-foreground text-sm">
                    No owner assigned.
                </p>
                <div
                    v-for="owner in owners"
                    :key="owner.id"
                    class="flex justify-between border-b pb-2 last:border-0"
                >
                    <span>{{ owner.name }}</span
                    ><span>{{ owner.pivot.ownership_share }}%</span>
                </div>
            </CardContent>
        </Card>
        <Card
            ><CardHeader
                ><CardTitle>{{ t('Inventory') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><div v-for="building in buildings" :key="building.id">
                    <p class="font-medium">{{ building.name }}</p>
                    <p class="text-muted-foreground text-sm">
                        {{
                            building.units
                                .map(
                                    (unit) => `${unit.number} (${unit.status})`,
                                )
                                .join(', ') || 'No units'
                        }}
                    </p>
                </div>
                <p v-if="units.length" class="text-sm">
                    Standalone units:
                    {{
                        units
                            .map((unit) => `${unit.number} (${unit.status})`)
                            .join(', ')
                    }}
                </p></CardContent
            ></Card
        ><Card
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
