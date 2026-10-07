<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import {
    amountNeedsCurrency,
    commercialForm,
    createBody,
    hasChanges,
    money,
    statusLabel,
    statusOptions,
    updateBody,
} from '@/lib/crm-commercial';
import type {
    CommercialForm,
    CommercialKind,
    CommercialRecord,
    CommercialSettings,
} from '@/lib/crm-commercial';
import type { Paginated } from '@/lib/crm-matches';

const props = defineProps<{ leadId: number; converted: boolean }>();
const { t } = useLocale();
const base = `/crm/leads/${props.leadId}/commercial`;
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const records = ref<Paginated<CommercialRecord>>({
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
});
const linkedDeal = ref<{ id: number; title: string } | null>(null);
const settings = ref<CommercialSettings | null>(null);
const canCreate = ref(false);
const loadError = ref('');
const message = ref('');
const busy = ref(false);

const dialogOpen = ref(false);
const editing = ref<CommercialRecord | null>(null);
const form = ref<CommercialForm>(commercialForm('offer'));
const errors = ref<Record<string, string>>({});

const kinds: { kind: CommercialKind; title: string; add: string }[] = [
    { kind: 'offer', title: 'Offers', add: 'Add offer' },
    { kind: 'contract', title: 'Contracts', add: 'Add contract' },
];
const rows = (kind: CommercialKind): CommercialRecord[] =>
    records.value.data.filter((record) => record.kind === kind);
const choices = (kind: CommercialKind) => settings.value?.statuses[kind] ?? [];
const label = (record: CommercialRecord): string =>
    statusLabel(choices(record.kind), record.status);
const options = computed(() =>
    statusOptions(choices(form.value.kind), form.value.status),
);
const dialogTitle = computed(() => {
    const noun = form.value.kind === 'offer' ? t('offer') : t('contract');

    return editing.value ? `${t('Edit')} ${noun}` : `${t('Add')} ${noun}`;
});
const writable = computed(() => canCreate.value && !props.converted);

async function load(page = 1): Promise<void> {
    try {
        const data = await apiJson<{
            records: Paginated<CommercialRecord>;
            linked_deal: { id: number; title: string } | null;
            permissions: { create: boolean };
            configuration: CommercialSettings;
        }>(`${base}?page=${page}`);
        records.value = data.records;
        linkedDeal.value = data.linked_deal;
        canCreate.value = data.permissions.create;
        settings.value = data.configuration;
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load offers and contracts.');
    }
}

function startAdd(kind: CommercialKind): void {
    const first = choices(kind).find((choice) => choice.active)?.value;
    editing.value = null;
    form.value = commercialForm(kind, undefined, first);
    form.value.deal_id = linkedDeal.value?.id ?? '';
    errors.value = {};
    dialogOpen.value = true;
}
function startEdit(record: CommercialRecord): void {
    editing.value = record;
    form.value = commercialForm(record.kind, record);
    errors.value = {};
    dialogOpen.value = true;
}

async function save(): Promise<void> {
    errors.value = {};
    if (amountNeedsCurrency(form.value)) {
        errors.value = {
            amount: t('Enter the amount and the currency together.'),
        };

        return;
    }
    busy.value = true;
    message.value = '';
    try {
        if (editing.value) {
            const body = updateBody(editing.value, form.value);
            if (hasChanges(body)) {
                await apiJson(`${base}/${editing.value.id}`, 'PUT', body);
            }
        } else {
            await apiJson(base, 'POST', createBody(form.value));
        }
        dialogOpen.value = false;
        message.value = t('Saved.');
        await load(records.value.current_page);
    } catch (failure) {
        if (failure instanceof ApiError) {
            const found = failure.fieldErrors();
            errors.value = Object.keys(found).length
                ? found
                : { form: failure.message };
        } else {
            errors.value = { form: t('Could not save.') };
        }
    } finally {
        busy.value = false;
    }
}

onMounted(() => void load());
</script>

<template>
    <div class="space-y-6">
        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>
        <p v-if="message" role="status" class="text-sm">{{ message }}</p>

        <Card>
            <CardHeader
                ><CardTitle class="text-eyebrow">{{
                    t('Deal')
                }}</CardTitle></CardHeader
            >
            <CardContent class="text-sm">
                <Link
                    v-if="linkedDeal"
                    :href="`/deals/${linkedDeal.id}`"
                    class="text-primary font-medium underline"
                    >{{ linkedDeal.title }}</Link
                >
                <p v-else class="text-muted-foreground">
                    {{ t('This lead has not been converted to a deal yet.') }}
                </p>
            </CardContent>
        </Card>

        <Card v-for="group in kinds" :key="group.kind">
            <CardHeader class="flex-row items-center justify-between">
                <CardTitle class="text-eyebrow"
                    >{{ t(group.title) }} ({{
                        rows(group.kind).length
                    }})</CardTitle
                >
                <Button
                    v-if="writable"
                    type="button"
                    size="sm"
                    @click="startAdd(group.kind)"
                    >{{ t(group.add) }}</Button
                >
            </CardHeader>
            <CardContent class="space-y-3">
                <p
                    v-if="!rows(group.kind).length"
                    class="text-muted-foreground text-sm"
                >
                    {{ t('Nothing recorded yet.') }}
                </p>
                <div
                    v-for="record in rows(group.kind)"
                    :key="record.id"
                    class="flex flex-wrap items-start justify-between gap-3 rounded-md border p-3 text-sm"
                >
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium">
                            {{ record.title
                            }}<span
                                v-if="record.reference"
                                class="text-muted-foreground font-normal"
                            >
                                · {{ record.reference }}</span
                            >
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ label(record) }} ·
                            {{ money(record.amount, record.currency) }}
                            <template v-if="record.party_name">
                                · {{ record.party_name }}</template
                            >
                            <template v-if="record.submitted_on">
                                · {{ t('Submitted') }}
                                {{ record.submitted_on }}</template
                            >
                            <template v-if="record.signed_on">
                                · {{ t('Signed') }}
                                {{ record.signed_on }}</template
                            >
                        </p>
                        <p v-if="record.notes" class="text-xs">
                            {{ record.notes }}
                        </p>
                    </div>
                    <Button
                        v-if="record.permissions.edit && !converted"
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="startEdit(record)"
                        >{{ t('Edit') }}</Button
                    >
                </div>
            </CardContent>
        </Card>
        <div
            v-if="records.last_page > 1"
            class="flex items-center justify-center gap-3 text-sm"
        >
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="records.current_page <= 1"
                @click="load(records.current_page - 1)"
                >{{ t('Previous') }}</Button
            >
            <span>{{ records.current_page }} / {{ records.last_page }}</span>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="records.current_page >= records.last_page"
                @click="load(records.current_page + 1)"
                >{{ t('Next') }}</Button
            >
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ dialogTitle }}</DialogTitle>
                    <DialogDescription>{{
                        t(
                            'Offers and contracts are tracked here; they do not send anything.',
                        )
                    }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="save">
                    <InputError :message="errors.form" />
                    <InputError :message="errors.expected_version" />
                    <InputError :message="errors.lead" />
                    <div class="space-y-1">
                        <Label for="cm-title">{{ t('Title') }}</Label>
                        <Input id="cm-title" v-model="form.title" required />
                        <InputError :message="errors.title" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="cm-reference">{{
                                t('Reference')
                            }}</Label>
                            <Input id="cm-reference" v-model="form.reference" />
                            <InputError :message="errors.reference" />
                        </div>
                        <div class="space-y-1">
                            <Label for="cm-status">{{ t('Status') }}</Label>
                            <select
                                id="cm-status"
                                v-model="form.status"
                                :class="selectClass"
                            >
                                <option
                                    v-for="choice in options"
                                    :key="choice.value"
                                    :value="choice.value"
                                >
                                    {{ choice.label
                                    }}<template v-if="!choice.active">
                                        ({{ t('archived') }})</template
                                    >
                                </option>
                            </select>
                            <InputError :message="errors.status" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="cm-party">{{ t('Other party') }}</Label>
                        <Input id="cm-party" v-model="form.party_name" />
                        <InputError :message="errors.party_name" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="cm-amount">{{ t('Amount') }}</Label>
                            <Input
                                id="cm-amount"
                                v-model="form.amount"
                                type="number"
                                min="0"
                                step="0.01"
                            />
                            <InputError :message="errors.amount" />
                        </div>
                        <div class="space-y-1">
                            <Label for="cm-currency">{{ t('Currency') }}</Label>
                            <Input
                                id="cm-currency"
                                v-model="form.currency"
                                maxlength="3"
                                placeholder="AED"
                            />
                            <InputError :message="errors.currency" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="cm-submitted">{{
                                t('Submitted on')
                            }}</Label>
                            <Input
                                id="cm-submitted"
                                v-model="form.submitted_on"
                                type="date"
                            />
                            <InputError :message="errors.submitted_on" />
                        </div>
                        <div class="space-y-1">
                            <Label for="cm-signed">{{ t('Signed on') }}</Label>
                            <Input
                                id="cm-signed"
                                v-model="form.signed_on"
                                type="date"
                            />
                            <InputError :message="errors.signed_on" />
                        </div>
                    </div>
                    <label
                        v-if="linkedDeal"
                        class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="form.deal_id"
                            type="checkbox"
                            :true-value="linkedDeal.id"
                            false-value=""
                        />{{ t('Linked to the deal') }}:
                        {{ linkedDeal.title }}</label
                    >
                    <InputError :message="errors.deal_id" />
                    <div class="space-y-1">
                        <Label for="cm-notes">{{ t('Notes') }}</Label>
                        <textarea
                            id="cm-notes"
                            v-model="form.notes"
                            rows="3"
                            class="border-input bg-background w-full rounded-md border p-2 text-sm"
                        />
                        <InputError :message="errors.notes" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="busy">{{
                            t('Save')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
