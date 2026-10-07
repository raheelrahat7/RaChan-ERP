<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import CrmChoiceListEditor from '@/components/CrmChoiceListEditor.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import type { ChoiceDraft } from '@/lib/crm-requirements';

type Configuration = {
    id: number | null;
    version: number;
    viewing_statuses: ChoiceDraft[];
    permissions: { read: boolean; edit: boolean };
};

const { t } = useLocale();
const configuration = ref<Configuration | null>(null);
const list = ref<ChoiceDraft[]>([]);
const original = ref<Set<string>>(new Set());
const errors = ref<Record<string, string>>({});
const loadError = ref('');
const message = ref('');
const busy = ref(false);

const canEdit = computed(() => configuration.value?.permissions.edit === true);
const dirty = computed(
    () =>
        JSON.stringify(list.value) !==
        JSON.stringify(configuration.value?.viewing_statuses ?? []),
);

function apply(data: { configuration: Configuration }): void {
    configuration.value = data.configuration;
    list.value = data.configuration.viewing_statuses.map((choice) => ({
        ...choice,
    }));
    original.value = new Set(list.value.map((choice) => choice.value));
    errors.value = {};
}

async function load(): Promise<void> {
    try {
        apply(await apiJson('/crm/settings/lead-matches'));
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
            await apiJson('/crm/settings/lead-matches', 'PUT', {
                expected_version: configuration.value.version,
                viewing_statuses: list.value,
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
    <Head :title="t('Viewing statuses')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <Link
            href="/crm/settings"
            class="text-muted-foreground text-sm underline"
            >{{ t('Back to CRM settings') }}</Link
        >
        <h1 class="font-display text-3xl leading-tight font-medium">
            {{ t('Viewing statuses') }}
        </h1>
        <p class="text-muted-foreground max-w-2xl text-sm">
            {{
                t(
                    'The viewing statuses offered on matched properties. The "scheduled" status requires a date. Archive a status to stop new use while keeping it on existing matches.',
                )
            }}
        </p>
        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>
        <Card v-if="configuration" class="max-w-4xl">
            <CardContent class="space-y-3">
                <p v-if="!canEdit" class="text-muted-foreground text-sm">
                    {{
                        t(
                            'Only owners and administrators can change these options.',
                        )
                    }}
                </p>
                <InputError :message="errors.form" />
                <InputError :message="errors.expected_version" />
                <CrmChoiceListEditor
                    v-model="list"
                    :can-edit="canEdit"
                    :saved-values="original"
                    :error="errors.viewing_statuses"
                />
                <div v-if="canEdit" class="flex items-center gap-3 pt-2">
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
            </CardContent>
        </Card>
    </div>
</template>
