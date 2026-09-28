<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t, status, locale } = useLocale();
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { SlaCycle } from '@/types/sla';

const props = defineProps<{
    jobId: number;
    cycles: SlaCycle[];
    canManage: boolean;
    canEnable: boolean;
}>();
const form = useForm({
    days: '',
    start: '',
    end: '',
    holidays: '',
    response_minutes: '',
    resolution_minutes: '',
});
const acknowledgement = useForm({});
function enable(): void {
    form.post(`/maintenance/${props.jobId}/sla`, { preserveScroll: true });
}
function acknowledge(): void {
    acknowledgement.post(`/maintenance/${props.jobId}/sla/acknowledge`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Card>
        <CardHeader
            ><CardTitle>{{ t('Service targets') }}</CardTitle></CardHeader
        >
        <CardContent class="space-y-4">
            <p class="text-muted-foreground text-sm">
                {{
                    t(
                        'Targets count working time. Manager-approved holds pause the clock. Resolution ends at final completion.',
                    )
                }}
            </p>
            <form
                v-if="canManage && canEnable && !cycles.length"
                class="grid gap-3 sm:grid-cols-2"
                @submit.prevent="enable"
            >
                <label class="space-y-1"
                    >{{ t('Working days (Monday 1–Sunday 7)')
                    }}<Input
                        v-model="form.days"
                        :placeholder="t('e.g. 1,2,3,4,5')"
                        required
                /></label>
                <label class="space-y-1"
                    >{{ t('Holidays (comma-separated local dates)')
                    }}<Input
                        v-model="form.holidays"
                        :placeholder="t('YYYY-MM-DD')"
                /></label>
                <label class="space-y-1"
                    >{{ t('Work starts')
                    }}<Input v-model="form.start" type="time" required
                /></label>
                <label class="space-y-1"
                    >{{ t('Work ends')
                    }}<Input v-model="form.end" type="time" required
                /></label>
                <label class="space-y-1"
                    >{{ t('Response target (working minutes)')
                    }}<Input
                        v-model="form.response_minutes"
                        type="number"
                        min="1"
                        max="525600"
                        required
                /></label>
                <label class="space-y-1"
                    >{{ t('Resolution target (working minutes)')
                    }}<Input
                        v-model="form.resolution_minutes"
                        type="number"
                        min="1"
                        max="525600"
                        required
                /></label>
                <p class="text-sm sm:col-span-2">
                    {{
                        t(
                            'Tracking starts when enabled. These calendar and target settings are preserved for this job and subsequent reopening cycles.',
                        )
                    }}
                </p>
                <p
                    v-for="(error, key) in form.errors"
                    :key="key"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p>
                <Button :disabled="form.processing">{{
                    t('Enable SLA tracking')
                }}</Button>
            </form>
            <p v-if="!cycles.length" class="text-sm">
                {{ t('No service targets configured.') }}
            </p>
            <div
                v-for="cycle in cycles"
                :key="cycle.id"
                class="space-y-2 rounded-md border p-3"
            >
                <p class="font-medium">
                    {{ t('Cycle') }} {{ cycle.cycle_number }} ·
                    {{
                        cycle.outcome
                            ? status(cycle.outcome)
                            : t(cycle.held_at ? 'On hold' : 'Active')
                    }}
                    · {{ cycle.timezone }}
                </p>
                <p class="text-sm">
                    {{ t('Started') }}
                    {{
                        new Date(cycle.started_at).toLocaleString(
                            locale === 'ar' ? 'ar' : 'en',
                        )
                    }}
                </p>
                <p class="text-sm">
                    {{ t('Response') }}:
                    {{ Math.floor(cycle.response_elapsed_seconds / 60) }} /
                    {{ cycle.response_seconds / 60 }}
                    {{ t('working minutes') }} ·
                    {{
                        cycle.response_breached
                            ? t('Breached')
                            : t('Within target')
                    }}{{
                        cycle.acknowledged_at ? ` · ${t('Acknowledged')}` : ''
                    }}
                </p>
                <p class="text-sm">
                    {{ t('Resolution') }}:
                    {{ Math.floor(cycle.resolution_elapsed_seconds / 60) }} /
                    {{ cycle.resolution_seconds / 60 }}
                    {{ t('working minutes') }} ·
                    {{
                        cycle.outcome === 'cancelled'
                            ? t('Cancelled')
                            : cycle.resolution_breached
                              ? t('Breached')
                              : t('Within target')
                    }}
                </p>
                <Button
                    v-if="!cycle.closed_at && !cycle.acknowledged_at"
                    :disabled="acknowledgement.processing"
                    @click="acknowledge"
                    >{{ t('Acknowledge job') }}</Button
                >
                <p
                    v-for="hold in cycle.holds"
                    :key="hold.start"
                    class="text-sm"
                >
                    {{ t('Hold') }}: {{ hold.reason }}
                </p>
            </div>
            <p
                v-for="(error, key) in acknowledgement.errors"
                :key="key"
                role="alert"
                class="text-destructive text-sm"
            >
                {{ error }}
            </p>
        </CardContent>
    </Card>
</template>
