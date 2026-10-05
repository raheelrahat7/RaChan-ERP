<script setup lang="ts">
import { Search, Settings2, SlidersHorizontal, X } from '@lucide/vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useLocale } from '@/composables/useLocale';
import {
    builtinPresets,
    defaultFieldKeys,
    deletePreset,
    loadFieldSelection,
    loadPresets,
    saveFieldSelection,
    savePreset,
    visibleFieldKeys,
} from '@/lib/crm-filter-presets';
import type { FilterClause, FilterPreset } from '@/lib/crm-filter-presets';
import type { Pipeline } from '@/types/crm-pipeline';

type FilterField = {
    key: string;
    label: string;
    type: string;
    group: string;
    options: string[];
};
type Draft = Record<string, { operator: string; value: string; to: string }>;
type Model = {
    q: string;
    pipeline_id: number;
    stage_id: number | null;
    assignee_id: number | null;
    filters: FilterClause[];
};

const props = defineProps<{
    catalog: FilterField[];
    pipelines: Pipeline[];
    members: { id: number; name: string }[];
    assigneeScoped: boolean;
    limitedVisibility: boolean;
    userId: number | null;
}>();
const model = defineModel<Model>({ required: true });
const emit = defineEmits<{ apply: [resetStage: boolean]; reset: [] }>();

const { t } = useLocale();
const open = ref(false);
const settingsOpen = ref(false);
const draft = reactive<Draft>({});
const selection = ref<string[] | null>(null);
const pendingSelection = ref<string[]>([]);
const findSetting = ref('');
const presets = ref<FilterPreset[]>([]);
const presetName = ref('');
const storage = (): Storage | null => {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
};

const shownKeys = computed(() =>
    visibleFieldKeys(props.catalog, selection.value),
);
const shownFields = computed(() =>
    props.catalog.filter((field) => shownKeys.value.includes(field.key)),
);
const selectedPipeline = computed(() =>
    props.pipelines.find(
        (pipeline) => pipeline.id === Number(model.value.pipeline_id),
    ),
);
const allPresets = computed(() => [
    ...builtinPresets(props.userId),
    ...presets.value,
]);
const settingsFields = computed(() =>
    props.catalog.filter((field) =>
        `${field.label} ${field.key}`
            .toLowerCase()
            .includes(findSetting.value.toLowerCase()),
    ),
);
const activeChips = computed(() =>
    model.value.filters.map((clause, index) => ({
        index,
        label: meta(clause.field)?.label ?? clause.field,
        text: ['empty', 'not_empty'].includes(clause.operator)
            ? clause.operator.replace('_', ' ')
            : `${clause.operator === 'equals' ? '' : `${clause.operator} `}${clause.value}`,
    })),
);

function meta(key: string): FilterField | undefined {
    return props.catalog.find((field) => field.key === key);
}
function operators(type: string): { value: string; label: string }[] {
    const shared = [
        { value: 'equals', label: 'Equals' },
        { value: 'not_equals', label: 'Not equal' },
        { value: 'empty', label: 'Empty' },
        { value: 'not_empty', label: 'Not empty' },
    ];
    if (['number', 'currency', 'date', 'datetime'].includes(type)) {
        return [
            ...shared,
            { value: 'gte', label: 'At least / after' },
            { value: 'lte', label: 'At most / before' },
            { value: 'between', label: 'Between' },
        ];
    }
    if (['text', 'long_text', 'email', 'phone', 'url'].includes(type)) {
        return [...shared, { value: 'contains', label: 'Contains' }];
    }

    return shared;
}
function inputType(type: string): string {
    if (['number', 'currency'].includes(type)) {
        return 'number';
    }
    if (['date', 'datetime'].includes(type)) {
        return type === 'datetime' ? 'datetime-local' : 'date';
    }

    return 'text';
}
function defaultOperator(type: string): string {
    return ['text', 'long_text', 'email', 'phone', 'url'].includes(type)
        ? 'contains'
        : 'equals';
}
function entry(field: FilterField): Draft[string] {
    draft[field.key] ??= {
        operator: defaultOperator(field.type),
        value: '',
        to: '',
    };

    return draft[field.key];
}
function syncDraft(): void {
    for (const key of Object.keys(draft)) {
        delete draft[key];
    }
    for (const clause of model.value.filters) {
        draft[clause.field] = {
            operator: clause.operator,
            value: clause.value ?? '',
            to: clause.to ?? '',
        };
    }
}
function commitDraft(): void {
    model.value.filters = Object.entries(draft)
        .filter(
            ([key, item]) =>
                meta(key) &&
                (['empty', 'not_empty'].includes(item.operator) ||
                    item.value !== ''),
        )
        .map(([field, item]) => ({
            field,
            operator: item.operator,
            value: item.value,
            ...(item.operator === 'between' ? { to: item.to } : {}),
        }));
}
function apply(resetStage = false): void {
    commitDraft();
    open.value = false;
    emit('apply', resetStage);
}
function reset(): void {
    for (const key of Object.keys(draft)) {
        delete draft[key];
    }
    model.value.q = '';
    model.value.assignee_id = null;
    model.value.stage_id = null;
    model.value.filters = [];
    open.value = false;
    emit('reset');
}
function removeChip(index: number): void {
    const removed = model.value.filters[index];
    model.value.filters.splice(index, 1);
    if (removed) {
        delete draft[removed.field];
    }
    emit('apply', false);
}
function usePreset(preset: FilterPreset): void {
    model.value.q = preset.state.q;
    model.value.assignee_id = preset.state.assignee_id;
    model.value.filters = structuredClone(preset.state.filters);
    syncDraft();
    apply();
}
function saveCurrent(): void {
    commitDraft();
    presets.value = savePreset(storage(), presetName.value, {
        q: model.value.q,
        assignee_id: model.value.assignee_id,
        filters: model.value.filters,
    });
    presetName.value = '';
}
function removePreset(id: string): void {
    presets.value = deletePreset(storage(), id);
}
function openSettings(): void {
    commitDraft();
    open.value = false;
    pendingSelection.value = [...shownKeys.value];
    findSetting.value = '';
    settingsOpen.value = true;
}
function toggleSetting(key: string, checked: boolean | 'indeterminate'): void {
    pendingSelection.value =
        checked === true
            ? [...pendingSelection.value, key]
            : pendingSelection.value.filter((item) => item !== key);
}
function applySettings(): void {
    selection.value = [...pendingSelection.value];
    saveFieldSelection(storage(), selection.value);
    settingsOpen.value = false;
}
function resetSettings(): void {
    pendingSelection.value = defaultFieldKeys(props.catalog);
}

onMounted(() => {
    selection.value = loadFieldSelection(storage());
    presets.value = loadPresets(storage());
    syncDraft();
});
watch(open, (isOpen) => {
    if (isOpen) {
        syncDraft();
    }
});
</script>

<template>
    <div class="flex min-w-0 flex-1 flex-col gap-2">
        <div class="flex gap-2">
            <form
                class="relative min-w-0 flex-1"
                role="search"
                @submit.prevent="apply()"
            >
                <Search
                    class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    v-model="model.q"
                    type="search"
                    class="ps-9"
                    :aria-label="t('Search leads and permitted fields')"
                    :placeholder="t('Filter and search')"
                />
            </form>
            <Popover v-model:open="open">
                <PopoverTrigger as-child>
                    <Button
                        type="button"
                        variant="outline"
                        :aria-expanded="open"
                    >
                        <SlidersHorizontal class="size-4" aria-hidden="true" />
                        <span class="hidden sm:inline">{{ t('Filters') }}</span>
                        <Badge
                            v-if="model.filters.length"
                            variant="secondary"
                            >{{ model.filters.length }}</Badge
                        >
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="end"
                    class="w-[min(46rem,calc(100vw-2rem))] p-0"
                >
                    <div
                        class="grid max-h-[70vh] sm:grid-cols-[12rem_minmax(0,1fr)]"
                    >
                        <div
                            class="bg-muted/40 border-b p-3 sm:border-e sm:border-b-0"
                        >
                            <p class="text-eyebrow mb-2">{{ t('Presets') }}</p>
                            <ul class="space-y-1">
                                <li
                                    v-for="preset in allPresets"
                                    :key="preset.id"
                                    class="flex items-center gap-1"
                                >
                                    <button
                                        type="button"
                                        class="hover:bg-muted min-w-0 flex-1 truncate rounded-md px-2 py-1.5 text-start text-sm"
                                        @click="usePreset(preset)"
                                    >
                                        {{ t(preset.name) }}
                                    </button>
                                    <button
                                        v-if="!preset.builtin"
                                        type="button"
                                        class="text-muted-foreground hover:text-foreground rounded p-1"
                                        :aria-label="`${t('Delete')} ${preset.name}`"
                                        @click="removePreset(preset.id)"
                                    >
                                        <X
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </li>
                            </ul>
                            <form
                                class="mt-3 flex gap-1"
                                @submit.prevent="saveCurrent"
                            >
                                <Input
                                    v-model="presetName"
                                    maxlength="60"
                                    class="h-8 text-xs"
                                    :aria-label="t('Preset name')"
                                    :placeholder="t('Save filter as…')"
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    :disabled="!presetName.trim()"
                                    >{{ t('Save') }}</Button
                                >
                            </form>
                            <p class="text-muted-foreground mt-2 text-xs">
                                {{ t('Presets are saved in this browser.') }}
                            </p>
                        </div>
                        <div class="space-y-3 overflow-y-auto p-4">
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="space-y-1 text-xs font-medium"
                                    >{{ t('Pipeline') }}
                                    <select
                                        v-model="model.pipeline_id"
                                        class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm font-normal"
                                        @change="model.stage_id = null"
                                    >
                                        <option
                                            v-for="pipeline in pipelines"
                                            :key="pipeline.id"
                                            :value="pipeline.id"
                                        >
                                            {{ pipeline.name
                                            }}{{
                                                !pipeline.active
                                                    ? ' (inactive)'
                                                    : ''
                                            }}
                                        </option>
                                    </select>
                                </label>
                                <label class="space-y-1 text-xs font-medium"
                                    >{{ t('Stage') }}
                                    <select
                                        v-model="model.stage_id"
                                        class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm font-normal"
                                    >
                                        <option :value="null">
                                            {{ t('All stages') }}
                                        </option>
                                        <option
                                            v-for="stage in selectedPipeline?.stages"
                                            :key="stage.id"
                                            :value="stage.id"
                                        >
                                            {{ stage.name }}
                                        </option>
                                    </select>
                                </label>
                                <label
                                    v-if="!assigneeScoped"
                                    class="space-y-1 text-xs font-medium"
                                    >{{ t('Responsible person') }}
                                    <select
                                        v-model="model.assignee_id"
                                        class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm font-normal"
                                    >
                                        <option :value="null">
                                            {{
                                                limitedVisibility
                                                    ? t('All accessible agents')
                                                    : t('All agents')
                                            }}
                                        </option>
                                        <option
                                            v-for="member in members"
                                            :key="member.id"
                                            :value="member.id"
                                        >
                                            {{ member.name }}
                                        </option>
                                    </select>
                                </label>
                            </div>
                            <div
                                v-for="field in shownFields"
                                :key="field.key"
                                class="grid gap-1 sm:grid-cols-[8rem_9rem_minmax(0,1fr)] sm:items-center sm:gap-2"
                            >
                                <span class="text-xs font-medium">{{
                                    field.label
                                }}</span>
                                <select
                                    v-model="entry(field).operator"
                                    :aria-label="`${t('Operator for')} ${field.label}`"
                                    class="border-input bg-background h-8 rounded-md border px-2 text-xs"
                                >
                                    <option
                                        v-for="operator in operators(
                                            field.type,
                                        )"
                                        :key="operator.value"
                                        :value="operator.value"
                                    >
                                        {{ t(operator.label) }}
                                    </option>
                                </select>
                                <div
                                    v-if="
                                        !['empty', 'not_empty'].includes(
                                            entry(field).operator,
                                        )
                                    "
                                    class="flex min-w-0 gap-1"
                                >
                                    <select
                                        v-if="field.type === 'user'"
                                        v-model="entry(field).value"
                                        :aria-label="`${t('Value for')} ${field.label}`"
                                        class="border-input bg-background h-8 min-w-0 flex-1 rounded-md border px-2 text-xs"
                                    >
                                        <option value="">
                                            {{ t('Select member') }}
                                        </option>
                                        <option
                                            v-for="member in members"
                                            :key="member.id"
                                            :value="String(member.id)"
                                        >
                                            {{ member.name }}
                                        </option>
                                    </select>
                                    <select
                                        v-else-if="
                                            field.options.length ||
                                            field.type === 'checkbox'
                                        "
                                        v-model="entry(field).value"
                                        :aria-label="`${t('Value for')} ${field.label}`"
                                        class="border-input bg-background h-8 min-w-0 flex-1 rounded-md border px-2 text-xs"
                                    >
                                        <option value="">
                                            {{ t('Select value') }}
                                        </option>
                                        <option
                                            v-for="option in field.type ===
                                            'checkbox'
                                                ? ['true', 'false']
                                                : field.options"
                                            :key="option"
                                            :value="option"
                                        >
                                            {{ option }}
                                        </option>
                                    </select>
                                    <Input
                                        v-else
                                        v-model="entry(field).value"
                                        :type="inputType(field.type)"
                                        class="h-8 min-w-0 flex-1 text-xs"
                                        :aria-label="`${t('Value for')} ${field.label}`"
                                    />
                                    <Input
                                        v-if="
                                            entry(field).operator === 'between'
                                        "
                                        v-model="entry(field).to"
                                        :type="inputType(field.type)"
                                        class="h-8 min-w-0 flex-1 text-xs"
                                        :aria-label="t('Range end')"
                                    />
                                </div>
                                <span v-else />
                            </div>
                            <button
                                type="button"
                                class="text-primary inline-flex items-center gap-1 text-xs underline-offset-2 hover:underline"
                                @click="openSettings"
                            >
                                <Settings2
                                    class="size-3.5"
                                    aria-hidden="true"
                                />{{ t('Filter field settings') }}
                            </button>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t p-3">
                        <Button type="button" variant="ghost" @click="reset">{{
                            t('Reset')
                        }}</Button>
                        <Button type="button" @click="apply(true)">{{
                            t('Search')
                        }}</Button>
                    </div>
                </PopoverContent>
            </Popover>
        </div>
        <div
            v-if="activeChips.length || model.q"
            class="flex flex-wrap items-center gap-2"
            :aria-label="t('Active filters')"
        >
            <Badge v-if="model.q" variant="secondary" class="gap-1"
                >{{ t('Search') }}: {{ model.q }}
                <button
                    type="button"
                    :aria-label="t('Clear search')"
                    @click="((model.q = ''), emit('apply', false))"
                >
                    <X class="size-3" aria-hidden="true" />
                </button>
            </Badge>
            <Badge
                v-for="chip in activeChips"
                :key="`${chip.label}-${chip.index}`"
                variant="secondary"
                class="gap-1"
            >
                {{ chip.label }} · {{ chip.text }}
                <button
                    type="button"
                    :aria-label="`${t('Remove filter')} ${chip.label}`"
                    @click="removeChip(chip.index)"
                >
                    <X class="size-3" aria-hidden="true" />
                </button>
            </Badge>
        </div>

        <Dialog v-model:open="settingsOpen">
            <DialogContent class="max-h-[90vh] max-w-3xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ t('Filter field settings') }}</DialogTitle>
                    <DialogDescription>{{
                        t('Choose which fields appear in the filter panel.')
                    }}</DialogDescription>
                </DialogHeader>
                <Input
                    v-model="findSetting"
                    type="search"
                    :aria-label="t('Find field')"
                    :placeholder="t('Find field')"
                />
                <section v-for="group in ['Lead', 'Activity']" :key="group">
                    <h3 class="text-eyebrow mb-2">{{ t(group) }}</h3>
                    <div class="grid gap-2 sm:grid-cols-2 md:grid-cols-3">
                        <label
                            v-for="field in settingsFields.filter(
                                (item) => item.group === group,
                            )"
                            :key="field.key"
                            class="hover:bg-muted flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                        >
                            <Checkbox
                                :model-value="
                                    pendingSelection.includes(field.key)
                                "
                                @update:model-value="
                                    toggleSetting(field.key, $event)
                                "
                            />
                            <span class="truncate" :title="field.label">{{
                                field.label
                            }}</span>
                        </label>
                    </div>
                </section>
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-t pt-3"
                >
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="
                                pendingSelection = catalog.map(
                                    (field) => field.key,
                                )
                            "
                            >{{ t('Select all') }}</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="resetSettings"
                            >{{ t('Default') }}</Button
                        >
                    </div>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="settingsOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="button" @click="applySettings">{{
                            t('Apply')
                        }}</Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
