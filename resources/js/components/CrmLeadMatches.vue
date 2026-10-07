<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
    CRITERIA_LABELS,
    createBody,
    criteriaText,
    hasChanges,
    matchForm,
    needsDate,
    priceText,
    scoreTone,
    statusChoices,
    updateBody,
} from '@/lib/crm-matches';
import type {
    LeadMatch,
    MatchCandidate,
    MatchForm,
    MatchListing,
    Paginated,
} from '@/lib/crm-matches';
import type { Choice } from '@/lib/crm-requirements';

const props = defineProps<{ leadId: number }>();
const { t } = useLocale();
const base = `/crm/leads/${props.leadId}/matches`;
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const matches = ref<Paginated<LeadMatch>>({
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
});
const candidates = ref<Paginated<MatchCandidate>>({
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
});
const statuses = ref<Choice[]>([]);
const canAdd = ref(false);
const query = ref('');
const loadError = ref('');
const suggestError = ref('');
const message = ref('');
const busy = ref(false);
let timer: number | null = null;

const dialogOpen = ref(false);
const editing = ref<LeadMatch | null>(null);
const adding = ref<MatchListing | null>(null);
const form = ref<MatchForm>(matchForm());
const errors = ref<Record<string, string>>({});

const statusLabel = (value: string): string =>
    statuses.value.find((status) => status.value === value)?.label ??
    value.replaceAll('_', ' ');
const toneClass = (percent: number): string =>
    ({
        high: 'bg-emerald-100 text-emerald-900',
        medium: 'bg-amber-100 text-amber-900',
        low: 'bg-muted text-muted-foreground',
    })[scoreTone(percent)];
const dialogTitle = computed(() =>
    editing.value ? t('Edit match') : t('Add match'),
);
const subject = computed(
    () => (editing.value?.listing ?? adding.value)?.reference ?? '',
);

async function loadMatches(page = 1): Promise<void> {
    try {
        const data = await apiJson<{
            matches: Paginated<LeadMatch>;
            permissions: { add: boolean };
            configuration: { viewing_statuses: Choice[] };
        }>(`${base}?page=${page}&per_page=25`);
        matches.value = data.matches;
        canAdd.value = data.permissions.add;
        statuses.value = data.configuration.viewing_statuses;
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load matches.');
    }
}
async function loadCandidates(page = 1): Promise<void> {
    try {
        const params = new URLSearchParams({
            page: String(page),
            per_page: '10',
        });
        if (query.value.trim()) {
            params.set('q', query.value.trim());
        }
        const data = await apiJson<{ candidates: Paginated<MatchCandidate> }>(
            `${base}/suggest?${params}`,
        );
        candidates.value = data.candidates;
        suggestError.value = '';
    } catch {
        suggestError.value = t('Could not load suggestions.');
    }
}
async function refresh(): Promise<void> {
    await Promise.all([
        loadMatches(matches.value.current_page),
        loadCandidates(candidates.value.current_page),
    ]);
}

watch(query, () => {
    if (timer) {
        clearTimeout(timer);
    }
    timer = window.setTimeout(() => void loadCandidates(1), 300);
});
onMounted(() => {
    void loadMatches();
    void loadCandidates();
});
onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }
});

function startAdd(listing: MatchListing): void {
    editing.value = null;
    adding.value = listing;
    form.value = matchForm();
    errors.value = {};
    dialogOpen.value = true;
}
function startEdit(match: LeadMatch): void {
    editing.value = match;
    adding.value = null;
    form.value = matchForm(match);
    errors.value = {};
    dialogOpen.value = true;
}

async function save(): Promise<void> {
    if (needsDate(form.value.viewing_status, form.value.viewing_at)) {
        errors.value = {
            viewing_at: t('A scheduled viewing needs a date and time.'),
        };

        return;
    }
    busy.value = true;
    errors.value = {};
    message.value = '';
    try {
        if (editing.value) {
            const body = updateBody(editing.value, form.value);
            if (hasChanges(body)) {
                await apiJson(`${base}/${editing.value.id}`, 'PUT', body);
            }
        } else if (adding.value) {
            await apiJson(
                base,
                'POST',
                createBody(adding.value.id, form.value),
            );
        }
        dialogOpen.value = false;
        message.value = t('Saved.');
        await refresh();
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

async function remove(match: LeadMatch): Promise<void> {
    if (!confirm(`${t('Remove')} ${match.listing.reference}?`)) {
        return;
    }
    try {
        await apiJson(`${base}/${match.id}`, 'DELETE', {
            expected_version: match.version,
        });
        message.value = t('Removed.');
        await refresh();
    } catch (failure) {
        loadError.value =
            failure instanceof ApiError
                ? (Object.values(failure.fieldErrors())[0] ?? failure.message)
                : t('Could not remove this match.');
    }
}
</script>

<template>
    <div class="space-y-6">
        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>
        <p v-if="message" role="status" class="text-sm">{{ message }}</p>

        <Card>
            <CardHeader
                ><CardTitle class="text-eyebrow"
                    >{{ t('Matched properties') }} ({{
                        matches.total
                    }})</CardTitle
                ></CardHeader
            >
            <CardContent class="space-y-3">
                <p
                    v-if="!matches.data.length"
                    class="text-muted-foreground text-sm"
                >
                    {{
                        t(
                            'No matched properties yet. Add one from the suggestions below.',
                        )
                    }}
                </p>
                <div
                    v-for="match in matches.data"
                    :key="match.id"
                    class="flex flex-wrap items-start justify-between gap-3 rounded-md border p-3 text-sm"
                >
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium">
                            {{ match.listing.reference }}
                            <span class="text-muted-foreground font-normal"
                                >· {{ match.listing.property?.name ?? ''
                                }}<template v-if="match.listing.unit">
                                    · {{ match.listing.unit.number }}</template
                                ></span
                            >
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ priceText(match.listing)
                            }}<template v-if="match.listing.property?.city">
                                · {{ match.listing.property.city }}</template
                            >
                            · {{ match.listing.purpose }}
                        </p>
                        <p class="flex flex-wrap items-center gap-2 text-xs">
                            <Badge variant="secondary">{{
                                statusLabel(match.viewing_status)
                            }}</Badge>
                            <span
                                v-if="match.viewing_at"
                                class="text-muted-foreground"
                                >{{
                                    new Date(match.viewing_at).toLocaleString()
                                }}</span
                            >
                            <Badge v-if="match.shared" variant="outline">{{
                                t('Shared')
                            }}</Badge>
                        </p>
                        <p
                            v-if="match.notes"
                            class="text-muted-foreground text-xs"
                        >
                            {{ match.notes }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-end">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="toneClass(match.match_percent)"
                                >{{ match.match_percent }}%</span
                            >
                            <p class="text-muted-foreground mt-1 text-xs">
                                {{
                                    match.score_source === 'manual'
                                        ? t('Manual score')
                                        : `${criteriaText(match.matched_on, match.evaluated_on)} ${t('criteria')}`
                                }}
                            </p>
                        </div>
                        <div
                            v-if="
                                match.permissions.edit ||
                                match.permissions.delete
                            "
                            class="flex gap-1"
                        >
                            <Button
                                v-if="match.permissions.edit"
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="startEdit(match)"
                                >{{ t('Edit') }}</Button
                            >
                            <Button
                                v-if="match.permissions.delete"
                                type="button"
                                size="sm"
                                variant="ghost"
                                @click="remove(match)"
                                >{{ t('Remove') }}</Button
                            >
                        </div>
                    </div>
                </div>
                <div
                    v-if="matches.last_page > 1"
                    class="flex items-center justify-center gap-3 text-sm"
                >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="matches.current_page <= 1"
                        @click="loadMatches(matches.current_page - 1)"
                        >{{ t('Previous') }}</Button
                    >
                    <span
                        >{{ matches.current_page }} /
                        {{ matches.last_page }}</span
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="matches.current_page >= matches.last_page"
                        @click="loadMatches(matches.current_page + 1)"
                        >{{ t('Next') }}</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader
                ><CardTitle class="text-eyebrow">{{
                    t('Suggested listings')
                }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-3">
                <p class="text-muted-foreground text-xs">
                    {{
                        t(
                            "Active listings with available units, ranked against this lead's requirement. Fill in the Requirement tab to improve the ranking.",
                        )
                    }}
                </p>
                <div class="relative max-w-sm">
                    <Search
                        class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="query"
                        type="search"
                        class="ps-9"
                        :aria-label="t('Search listings')"
                        :placeholder="t('Search listings')"
                    />
                </div>
                <p
                    v-if="suggestError"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ suggestError }}
                </p>
                <p
                    v-else-if="!candidates.data.length"
                    class="text-muted-foreground text-sm"
                >
                    {{ t('No suggestions right now.') }}
                </p>
                <div
                    v-for="candidate in candidates.data"
                    :key="candidate.listing.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm"
                >
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ candidate.listing.reference }}
                            <span class="text-muted-foreground font-normal"
                                >· {{ candidate.listing.property?.name ?? ''
                                }}<template v-if="candidate.listing.unit">
                                    ·
                                    {{
                                        candidate.listing.unit.number
                                    }}</template
                                ></span
                            >
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ priceText(candidate.listing) }} ·
                            {{ candidate.listing.purpose }}
                            <template v-if="candidate.matched_on.length">
                                ·
                                {{
                                    candidate.matched_on
                                        .map((key) =>
                                            t(CRITERIA_LABELS[key] ?? key),
                                        )
                                        .join(', ')
                                }}</template
                            >
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="toneClass(candidate.match_percent)"
                            >{{ candidate.match_percent }}%
                            <span class="font-normal"
                                >({{
                                    criteriaText(
                                        candidate.matched_on,
                                        candidate.evaluated_on,
                                    )
                                }})</span
                            ></span
                        >
                        <Button
                            v-if="canAdd && candidate.permissions.add"
                            type="button"
                            size="sm"
                            @click="startAdd(candidate.listing)"
                            >{{ t('Add match') }}</Button
                        >
                    </div>
                </div>
                <div
                    v-if="candidates.last_page > 1"
                    class="flex items-center justify-center gap-3 text-sm"
                >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="candidates.current_page <= 1"
                        @click="loadCandidates(candidates.current_page - 1)"
                        >{{ t('Previous') }}</Button
                    >
                    <span
                        >{{ candidates.current_page }} /
                        {{ candidates.last_page }}</span
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="
                            candidates.current_page >= candidates.last_page
                        "
                        @click="loadCandidates(candidates.current_page + 1)"
                        >{{ t('Next') }}</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ dialogTitle }}</DialogTitle>
                    <DialogDescription>{{ subject }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="save">
                    <InputError :message="errors.form" />
                    <InputError :message="errors.expected_version" />
                    <InputError :message="errors.lead" />
                    <InputError :message="errors.listing_id" />
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="mt-status">{{
                                t('Viewing status')
                            }}</Label>
                            <select
                                id="mt-status"
                                v-model="form.viewing_status"
                                :class="selectClass"
                            >
                                <option
                                    v-for="choice in statusChoices(
                                        statuses,
                                        form.viewing_status,
                                    )"
                                    :key="choice.value"
                                    :value="choice.value"
                                >
                                    {{ choice.label
                                    }}<template v-if="!choice.active">
                                        ({{ t('archived') }})</template
                                    >
                                </option>
                            </select>
                            <InputError :message="errors.viewing_status" />
                        </div>
                        <div class="space-y-1">
                            <Label for="mt-at">{{
                                t('Viewing date and time')
                            }}</Label>
                            <Input
                                id="mt-at"
                                v-model="form.viewing_at"
                                type="datetime-local"
                            />
                            <InputError :message="errors.viewing_at" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="mt-override">{{
                            t('Match score override (%)')
                        }}</Label>
                        <Input
                            id="mt-override"
                            v-model="form.override"
                            type="number"
                            min="0"
                            max="100"
                            :placeholder="t('Automatic')"
                        />
                        <InputError :message="errors.match_percent_override" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="form.shared" type="checkbox" />{{
                            t('Marked as shared with the client')
                        }}</label
                    >
                    <div class="space-y-1">
                        <Label for="mt-notes">{{ t('Notes') }}</Label>
                        <textarea
                            id="mt-notes"
                            v-model="form.notes"
                            rows="3"
                            maxlength="5000"
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
