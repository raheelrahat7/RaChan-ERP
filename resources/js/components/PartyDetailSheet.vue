<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import {
    SINGULAR,
    createBody,
    hasChanges,
    partyForm,
    updateBody,
} from '@/lib/parties';
import type { PartyRecord, PartyType } from '@/lib/parties';

const props = defineProps<{
    type: PartyType;
    /** Null when adding a new record. */
    recordId: number | null;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();
const { t } = useLocale();

const record = ref<PartyRecord | null>(null);
const form = reactive(partyForm());
const errors = ref<Record<string, string>>({});
const message = ref('');
const loading = ref(false);
const processing = ref(false);
const base = () => `/real-estate/people/${props.type}`;
const readOnly = () =>
    loading.value || (record.value !== null && !record.value.permissions.edit);

async function load(): Promise<void> {
    errors.value = {};
    message.value = '';
    record.value = null;
    Object.assign(form, partyForm());
    if (props.recordId === null) {
        return;
    }
    loading.value = true;
    try {
        const data = await apiJson<{ record: PartyRecord }>(
            `${base()}/${props.recordId}`,
        );
        record.value = data.record;
        Object.assign(form, partyForm(data.record));
    } catch {
        message.value = t('Could not load this record.');
    } finally {
        loading.value = false;
    }
}
watch(open, (isOpen) => isOpen && void load());

async function save(): Promise<void> {
    errors.value = {};
    message.value = '';
    processing.value = true;
    try {
        if (record.value) {
            const body = updateBody(record.value, form);
            if (hasChanges(body)) {
                await apiJson(`${base()}/${record.value.id}`, 'PUT', body);
            }
        } else {
            await apiJson(base(), 'POST', createBody(form));
        }
        emit('saved');
        open.value = false;
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.fieldErrors();
            message.value =
                errors.value.expected_version ??
                (Object.keys(errors.value).length ? '' : error.message);
        } else {
            message.value = t('Something went wrong. Please try again.');
        }
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-lg" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">{{
                    recordId === null
                        ? type === 'owners'
                            ? t('Add owner')
                            : t('Add developer')
                        : t(`Edit ${SINGULAR[type]}`)
                }}</SheetTitle>
                <SheetDescription>{{ record?.name }}</SheetDescription>
            </SheetHeader>
            <form
                id="party-detail-form"
                class="flex-1 space-y-4 overflow-y-auto p-4"
                @submit.prevent="save"
            >
                <p v-if="message" class="text-destructive text-sm" role="alert">
                    {{ message }}
                </p>
                <p v-if="loading" class="text-muted-foreground text-sm">
                    {{ t('Loading…') }}
                </p>
                <div class="space-y-1">
                    <Label for="pd-name">{{ t('Name') }}</Label>
                    <Input
                        id="pd-name"
                        v-model="form.name"
                        required
                        :disabled="readOnly()"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="pd-email">{{ t('Email') }}</Label>
                        <Input
                            id="pd-email"
                            v-model="form.email"
                            type="email"
                            :disabled="readOnly()"
                        />
                        <InputError :message="errors.email" />
                    </div>
                    <div class="space-y-1">
                        <Label for="pd-phone">{{ t('Phone') }}</Label>
                        <Input
                            id="pd-phone"
                            v-model="form.phone"
                            :disabled="readOnly()"
                        />
                        <InputError :message="errors.phone" />
                    </div>
                </div>
                <div class="space-y-1">
                    <Label for="pd-ref">{{ t('Reference') }}</Label>
                    <Input
                        id="pd-ref"
                        v-model="form.reference"
                        :disabled="readOnly()"
                    />
                    <InputError :message="errors.reference" />
                </div>
                <div class="space-y-1">
                    <Label for="pd-terms">{{ t('Payment terms') }}</Label>
                    <textarea
                        id="pd-terms"
                        v-model="form.payment_terms"
                        rows="3"
                        maxlength="5000"
                        :disabled="readOnly()"
                        class="border-input bg-background w-full rounded-md border p-2 text-sm"
                    />
                    <InputError :message="errors.payment_terms" />
                </div>
                <div class="space-y-1">
                    <Label for="pd-notes">{{ t('Commission notes') }}</Label>
                    <textarea
                        id="pd-notes"
                        v-model="form.commission_notes"
                        rows="3"
                        maxlength="5000"
                        :disabled="readOnly()"
                        class="border-input bg-background w-full rounded-md border p-2 text-sm"
                    />
                    <InputError :message="errors.commission_notes" />
                </div>

                <section v-if="record" class="space-y-2">
                    <h3 class="text-eyebrow">
                        {{ t('Linked listings') }} ({{
                            record.listings?.length ?? 0
                        }})
                    </h3>
                    <p
                        v-if="!record.listings?.length"
                        class="text-muted-foreground text-sm"
                    >
                        {{ t('No listings linked yet.') }}
                    </p>
                    <ul
                        v-else
                        role="list"
                        class="divide-y rounded-md border text-sm"
                    >
                        <li
                            v-for="listing in record.listings"
                            :key="listing.id"
                            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                        >
                            <span
                                >{{ listing.reference }} ·
                                {{ listing.purpose }} ·
                                {{ listing.currency ?? 'AED' }}
                                {{ listing.price }}</span
                            >
                            <Badge variant="secondary">{{
                                listing.status
                            }}</Badge>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="record && type === 'developers'"
                    class="space-y-2"
                >
                    <h3 class="text-eyebrow">
                        {{ t('Off-plan projects') }} ({{
                            record.offplan_projects?.length ?? 0
                        }})
                    </h3>
                    <p
                        v-if="!record.offplan_projects?.length"
                        class="text-muted-foreground text-sm"
                    >
                        {{ t('No off-plan projects yet.') }}
                    </p>
                    <ul
                        v-else
                        role="list"
                        class="divide-y rounded-md border text-sm"
                    >
                        <li
                            v-for="project in record.offplan_projects"
                            :key="project.id"
                            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                        >
                            <a
                                :href="`/real-estate/off-plan/${project.id}`"
                                class="text-accent-text hover:underline"
                                >{{ project.code }} · {{ project.name }}</a
                            >
                            <Badge
                                v-if="project.workflow_status"
                                variant="secondary"
                                >{{ project.workflow_status }}</Badge
                            >
                        </li>
                    </ul>
                </section>
            </form>
            <SheetFooter
                class="bg-background flex-row justify-end gap-2 border-t p-4"
            >
                <Button type="button" variant="outline" @click="open = false">{{
                    t('Close')
                }}</Button>
                <Button
                    v-if="!readOnly()"
                    type="submit"
                    form="party-detail-form"
                    :disabled="processing || loading"
                    >{{ t('Save') }}</Button
                >
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
