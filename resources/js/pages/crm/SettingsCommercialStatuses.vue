<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import CrmChoiceListEditor from '@/components/CrmChoiceListEditor.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import type { ChoiceDraft } from '@/lib/crm-requirements';

type Configuration = {
    version: number;
    statuses: { offer: ChoiceDraft[]; contract: ChoiceDraft[] };
    permissions: { read: boolean; edit: boolean };
};
type Kind = 'offer' | 'contract';

const { t } = useLocale();
const configuration = ref<Configuration | null>(null);
const lists = ref<Record<Kind, ChoiceDraft[]>>({ offer: [], contract: [] });
const originals = ref<Record<Kind, Set<string>>>({
    offer: new Set(),
    contract: new Set(),
});
const errors = ref<Record<string, string>>({});
const loadError = ref('');
const message = ref('');
const busy = ref(false);
const sections: { kind: Kind; title: string }[] = [
    { kind: 'offer', title: 'Offer statuses' },
    { kind: 'contract', title: 'Contract statuses' },
];

const canEdit = computed(() => configuration.value?.permissions.edit === true);
const dirty = computed(
    () =>
        JSON.stringify(lists.value) !==
        JSON.stringify(configuration.value?.statuses ?? {}),
);

function apply(data: { configuration: Configuration }): void {
    configuration.value = data.configuration;
    const copy = (kind: Kind): ChoiceDraft[] =>
        data.configuration.statuses[kind].map((choice) => ({ ...choice }));
    lists.value = { offer: copy('offer'), contract: copy('contract') };
    originals.value = {
        offer: new Set(lists.value.offer.map((choice) => choice.value)),
        contract: new Set(lists.value.contract.map((choice) => choice.value)),
    };
    errors.value = {};
}

async function load(): Promise<void> {
    try {
        apply(await apiJson('/crm/settings/lead-commercial'));
    } catch {
        loadError.value = t('Could not load these settings.');
    }
}

async function save(): Promise<void> {
    if (!configuration.value) {
        return;
    }
    busy.value = true;
    errors.value = {};
    message.value = '';
    try {
        apply(
            await apiJson('/crm/settings/lead-commercial', 'PUT', {
                expected_version: configuration.value.version,
                statuses: lists.value,
            }),
        );
        message.value = t('Saved.');
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

onMounted(load);
</script>

<template>
    <Head :title="t('Offer and contract statuses')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <Link
            href="/crm/settings"
            class="text-muted-foreground text-sm underline"
            >{{ t('Back to CRM settings') }}</Link
        >
        <h1 class="font-display text-3xl leading-tight font-medium">
            {{ t('Offer and contract statuses') }}
        </h1>
        <p class="text-muted-foreground max-w-2xl text-sm">
            {{
                t(
                    'The statuses offered on lead offers and contracts. Archive a status to stop new use while keeping it on existing records.',
                )
            }}
        </p>
        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>
        <div v-if="configuration" class="max-w-4xl space-y-4">
            <p v-if="!canEdit" class="text-muted-foreground text-sm">
                {{
                    t(
                        'Only owners and administrators can change these options.',
                    )
                }}
            </p>
            <InputError :message="errors.form" />
            <InputError :message="errors.expected_version" />
            <Card v-for="section in sections" :key="section.kind">
                <CardHeader
                    ><CardTitle class="text-eyebrow">{{
                        t(section.title)
                    }}</CardTitle></CardHeader
                >
                <CardContent>
                    <CrmChoiceListEditor
                        v-model="lists[section.kind]"
                        :can-edit="canEdit"
                        :saved-values="originals[section.kind]"
                        :error="errors[`statuses.${section.kind}`]"
                    />
                </CardContent>
            </Card>
            <div v-if="canEdit" class="flex items-center gap-3">
                <Button
                    type="button"
                    :disabled="busy || !dirty"
                    @click="save"
                    >{{ t('Save') }}</Button
                >
                <span v-if="message" role="status" class="text-sm">{{
                    message
                }}</span>
            </div>
        </div>
    </div>
</template>
