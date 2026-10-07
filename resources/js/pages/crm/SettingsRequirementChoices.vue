<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import CrmChoiceListEditor from '@/components/CrmChoiceListEditor.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import { CHOICE_FIELDS, FIELD_LABELS } from '@/lib/crm-requirements';
import type {
    ChoiceDraft,
    RequirementConfiguration,
} from '@/lib/crm-requirements';

const { t } = useLocale();
const configuration = ref<RequirementConfiguration | null>(null);
const field = ref<string>(CHOICE_FIELDS[0]);
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
        JSON.stringify(configuration.value?.choices[field.value] ?? []),
);

function pick(next: string): void {
    field.value = next;
    list.value = (configuration.value?.choices[next] ?? []).map((choice) => ({
        ...choice,
    }));
    original.value = new Set(list.value.map((choice) => choice.value));
    errors.value = {};
    message.value = '';
}
function apply(data: { configuration: RequirementConfiguration }): void {
    configuration.value = data.configuration;
    pick(field.value);
}

async function load(): Promise<void> {
    try {
        apply(await apiJson('/crm/settings/lead-requirements'));
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
            await apiJson('/crm/settings/lead-requirements', 'PUT', {
                expected_version: configuration.value.version,
                choices: { [field.value]: list.value },
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
    <Head :title="t('Requirement options')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <Link
            href="/crm/settings"
            class="text-muted-foreground text-sm underline"
            >{{ t('Back to CRM settings') }}</Link
        >
        <h1 class="font-display text-3xl leading-tight font-medium">
            {{ t('Requirement options') }}
        </h1>
        <p class="text-muted-foreground max-w-2xl text-sm">
            {{
                t(
                    'The choices offered in the lead Requirement tab. Archive an option to stop new selections while keeping it on existing leads.',
                )
            }}
        </p>
        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>

        <div
            v-if="configuration"
            class="grid gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]"
        >
            <nav :aria-label="t('Requirement options')">
                <ul class="space-y-1">
                    <li v-for="item in CHOICE_FIELDS" :key="item">
                        <button
                            type="button"
                            class="hover:bg-muted flex w-full items-center justify-between rounded-md px-3 py-2 text-start text-sm"
                            :class="
                                item === field ? 'bg-muted font-medium' : ''
                            "
                            :aria-current="item === field ? 'true' : undefined"
                            @click="pick(item)"
                        >
                            {{ t(FIELD_LABELS[item]) }}
                            <Badge variant="outline">{{
                                configuration.choices[item]?.length ?? 0
                            }}</Badge>
                        </button>
                    </li>
                </ul>
            </nav>
            <Card>
                <CardContent class="space-y-3">
                    <h2 class="text-eyebrow">{{ t(FIELD_LABELS[field]) }}</h2>
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
                        :error="errors[`choices.${field}`]"
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
    </div>
</template>
