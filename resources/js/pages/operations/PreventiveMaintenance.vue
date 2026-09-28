<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Plan = {
    id: number;
    title: string;
    frequency_days: number;
    next_due_on: string;
    is_active: boolean;
    auto_generate_enabled: boolean;
    requires_manager_confirmation: boolean;
    automation_last_run_at: string | null;
    automation_last_error: string | null;
    occurrences: {
        id: number;
        reference: string;
        preventive_due_on: string;
        status: string;
    }[];
    property: { name: string };
    vendor: { name: string } | null;
};
const props = defineProps<{
    plans: Plan[];
    properties: { id: number; name: string }[];
    vendors: { id: number; name: string }[];
    canManage: boolean;
    today: string;
}>();

const generating = ref<number | null>(null);
const generationError = ref('');
const settingsPending = ref<number | null>(null);
const settingsError = ref('');
function saveSettings(plan: Plan): void {
    if (settingsPending.value !== null) return;
    settingsPending.value = plan.id;
    settingsError.value = '';
    router.put(
        `/preventive-maintenance/${plan.id}/automation`,
        {
            auto_generate_enabled: plan.auto_generate_enabled,
            requires_manager_confirmation: plan.requires_manager_confirmation,
            is_active: plan.is_active,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                settingsError.value =
                    Object.values(errors)[0] ?? 'Unable to save settings.';
            },
            onFinish: () => {
                settingsPending.value = null;
            },
        },
    );
}
const confirmation = ref<Record<number, boolean>>({});

const form = useForm({
    property_id: '',
    vendor_id: '',
    title: '',
    frequency_days: '90',
    next_due_on: '',
});
function create(): void {
    form.post('/preventive-maintenance', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function generate(plan: Plan): void {
    if (
        generating.value === null &&
        confirm(`Generate work order for ${plan.title}?`)
    ) {
        generating.value = plan.id;
        generationError.value = '';
        router.post(
            `/preventive-maintenance/${plan.id}/generate`,
            {
                due_on: plan.next_due_on,
                requires_manager_confirmation:
                    confirmation.value[plan.id] ??
                    plan.requires_manager_confirmation,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    generationError.value =
                        errors.due_on ?? 'Unable to generate this work order.';
                },
                onFinish: () => {
                    generating.value = null;
                },
            },
        );
    }
}
function isDue(plan: Plan): boolean {
    return plan.is_active && plan.next_due_on <= props.today;
}
</script>

<template>
    <Head title="Preventive maintenance" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Preventive maintenance"
            description="Schedule recurring service and generate due work orders."
        />
        <Card v-if="canManage">
            <CardHeader><CardTitle>New service plan</CardTitle></CardHeader>
            <CardContent>
                <form class="flex flex-wrap gap-3" @submit.prevent="create">
                    <select
                        v-model="form.property_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Property') }}</option>
                        <option
                            v-for="property in properties"
                            :key="property.id"
                            :value="String(property.id)"
                        >
                            {{ property.name }}
                        </option>
                    </select>
                    <select
                        v-model="form.vendor_id"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">No vendor</option>
                        <option
                            v-for="vendor in vendors"
                            :key="vendor.id"
                            :value="String(vendor.id)"
                        >
                            {{ vendor.name }}
                        </option>
                    </select>
                    <Input
                        v-model="form.title"
                        placeholder="Service title"
                        required
                    />
                    <Input
                        v-model="form.frequency_days"
                        type="number"
                        min="1"
                        placeholder="Frequency days"
                        required
                    />
                    <Input v-model="form.next_due_on" type="date" required />
                    <Button :disabled="form.processing">Create plan</Button>
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>Service plans</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <p
                    v-if="settingsError"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ settingsError }}
                </p>
                <p class="text-muted-foreground text-sm">
                    Automatic generation runs every 15 minutes when the
                    scheduler is running. Every missed occurrence is preserved;
                    large backlogs continue in later batches. Generated jobs are
                    unassigned until a manager allocates them.
                </p>
                <p
                    v-if="generationError"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ generationError }}
                </p>
                <p v-if="!plans.length" class="text-muted-foreground text-sm">
                    No preventive-maintenance plans yet.
                </p>
                <div
                    v-for="plan in plans"
                    :key="plan.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-b pb-3 last:border-0"
                >
                    <span
                        >{{ plan.title }} · {{ plan.property.name }} · every
                        {{ plan.frequency_days }} days ·
                        <span
                            :class="
                                isDue(plan)
                                    ? 'text-amber-600'
                                    : 'text-muted-foreground'
                            "
                            >due {{ plan.next_due_on }}</span
                        ></span
                    >
                    <form
                        v-if="canManage"
                        class="flex w-full flex-wrap items-center gap-3"
                        @submit.prevent="saveSettings(plan)"
                    >
                        <label class="flex items-center gap-2 text-sm"
                            ><input v-model="plan.is_active" type="checkbox" />
                            Active plan</label
                        >
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="plan.auto_generate_enabled"
                                type="checkbox"
                            />
                            Generate automatically</label
                        >
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="plan.requires_manager_confirmation"
                                type="checkbox"
                            />
                            Require manager confirmation for automatic
                            jobs</label
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="settingsPending !== null"
                            >Save settings</Button
                        >
                        <p
                            v-if="plan.automation_last_run_at"
                            class="w-full text-sm"
                        >
                            Last automatic run:
                            {{ plan.automation_last_run_at }}
                        </p>
                        <p
                            v-if="plan.automation_last_error"
                            role="alert"
                            class="text-destructive w-full text-sm"
                        >
                            {{ plan.automation_last_error }} Failed plans retry
                            after one hour; saving corrected settings clears
                            this error.
                        </p>
                    </form>
                    <div
                        v-if="plan.occurrences.length"
                        class="w-full space-y-1 text-sm"
                    >
                        <p class="font-medium">Recent occurrences (up to 20)</p>
                        <p
                            v-for="occurrence in plan.occurrences"
                            :key="occurrence.id"
                        >
                            <Link
                                :href="`/maintenance/${occurrence.id}/job-card`"
                                class="underline underline-offset-4"
                                >{{ occurrence.reference }}</Link
                            >
                            · {{ occurrence.preventive_due_on }} ·
                            {{ occurrence.status }}
                        </p>
                    </div>
                    <label
                        v-if="canManage && isDue(plan)"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            :checked="
                                confirmation[plan.id] ??
                                plan.requires_manager_confirmation
                            "
                            @change="
                                confirmation[plan.id] = (
                                    $event.target as HTMLInputElement
                                ).checked
                            "
                            type="checkbox"
                            :disabled="generating !== null"
                        />
                        Require manager confirmation for this work order
                    </label>
                    <Button
                        v-if="canManage && isDue(plan)"
                        size="sm"
                        :disabled="generating !== null"
                        @click="generate(plan)"
                        >Generate work order</Button
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
