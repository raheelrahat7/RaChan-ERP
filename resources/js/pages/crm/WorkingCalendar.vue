<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import {
    WEEKDAYS,
    addHoliday,
    calendarPayload,
    defaultWeek,
    weekErrors,
    weekFrom,
    workingHoursPerWeek,
} from '@/lib/crm-calendar';
import type { ServerCalendar, Week } from '@/lib/crm-calendar';

const { t } = useLocale();
const week = ref<Week>(defaultWeek());
const holidays = ref<string[]>([]);
const timezone = ref('');
const configured = ref(true);
const newHoliday = ref('');
const loading = ref(true);
const loadError = ref('');
const message = ref('');
const serverError = ref('');
const busy = ref(false);

const errors = computed(() => weekErrors(week.value));
const hours = computed(() => workingHoursPerWeek(week.value));

async function load(): Promise<void> {
    try {
        const data = await apiJson<{ calendar: ServerCalendar }>(
            '/crm/settings/calendar',
        );
        configured.value = data.calendar !== null;
        week.value = weekFrom(data.calendar);
        holidays.value = data.calendar?.holidays ?? [];
        timezone.value = data.calendar?.timezone ?? '';
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load the working calendar.');
    } finally {
        loading.value = false;
    }
}

function add(): void {
    holidays.value = addHoliday(holidays.value, newHoliday.value);
    newHoliday.value = '';
}

async function save(): Promise<void> {
    if (Object.keys(errors.value).length) {
        return;
    }
    busy.value = true;
    serverError.value = '';
    message.value = '';
    try {
        await apiJson(
            '/crm/settings/calendar',
            'PUT',
            calendarPayload(week.value, holidays.value),
        );
        configured.value = true;
        message.value = t('Saved.');
    } catch (failure) {
        serverError.value =
            failure instanceof ApiError
                ? (Object.values(failure.fieldErrors())[0] ?? failure.message)
                : t('Could not save.');
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Head :title="t('Working calendar')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Working calendar"
            description="Working days, hours and holidays used when automation delays count in working hours only."
        >
            <template #actions>
                <Link href="/crm/settings" class="text-sm underline">{{
                    t('Back to CRM settings')
                }}</Link>
            </template>
        </PageHeader>
        <div class="flex max-w-3xl flex-col gap-4">
            <p v-if="loadError" role="alert" class="text-destructive text-sm">
                {{ loadError }}
            </p>
            <p
                v-if="!loading && !configured"
                class="text-muted-foreground text-sm"
            >
                {{
                    t(
                        'No calendar saved yet. Review the suggested week and save it.',
                    )
                }}
            </p>

            <Card>
                <CardContent class="space-y-3">
                    <h2 class="text-eyebrow">{{ t('Working week') }}</h2>
                    <div
                        v-for="day in WEEKDAYS"
                        :key="day.iso"
                        class="grid grid-cols-[8rem_1fr] items-center gap-3 sm:grid-cols-[8rem_auto_auto]"
                    >
                        <label
                            class="flex items-center gap-2 text-sm font-medium"
                        >
                            <input
                                v-model="week[day.iso].on"
                                type="checkbox"
                            />{{ t(day.label) }}
                        </label>
                        <template v-if="week[day.iso].on">
                            <div class="flex items-center gap-2">
                                <Label
                                    :for="`start-${day.iso}`"
                                    class="sr-only"
                                    >{{ t('Start') }}</Label
                                >
                                <Input
                                    :id="`start-${day.iso}`"
                                    v-model="week[day.iso].start"
                                    type="time"
                                    class="w-32"
                                />
                                <span aria-hidden="true">–</span>
                                <Label
                                    :for="`end-${day.iso}`"
                                    class="sr-only"
                                    >{{ t('End') }}</Label
                                >
                                <Input
                                    :id="`end-${day.iso}`"
                                    v-model="week[day.iso].end"
                                    type="time"
                                    class="w-32"
                                />
                            </div>
                            <InputError
                                v-if="errors[day.iso]"
                                :message="t(errors[day.iso])"
                            />
                        </template>
                        <span v-else class="text-muted-foreground text-sm">{{
                            t('Day off')
                        }}</span>
                    </div>
                    <InputError v-if="errors[0]" :message="t(errors[0])" />
                    <p class="text-muted-foreground text-xs">
                        {{ hours }} {{ t('working hours per week')
                        }}<template v-if="timezone"> · {{ timezone }}</template>
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardContent class="space-y-3">
                    <h2 class="text-eyebrow">{{ t('Holidays') }}</h2>
                    <form class="flex items-end gap-2" @submit.prevent="add">
                        <div class="space-y-1">
                            <Label for="holiday">{{ t('Date') }}</Label>
                            <Input
                                id="holiday"
                                v-model="newHoliday"
                                type="date"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="!newHoliday"
                            >{{ t('Add holiday') }}</Button
                        >
                    </form>
                    <p
                        v-if="!holidays.length"
                        class="text-muted-foreground text-sm"
                    >
                        {{ t('No holidays added.') }}
                    </p>
                    <ul class="flex flex-wrap gap-2">
                        <li
                            v-for="date in holidays"
                            :key="date"
                            class="bg-muted flex items-center gap-1 rounded-md px-2 py-1 text-sm"
                        >
                            {{ date }}
                            <button
                                type="button"
                                :aria-label="`${t('Remove')} ${date}`"
                                @click="
                                    holidays = holidays.filter(
                                        (item) => item !== date,
                                    )
                                "
                            >
                                <X class="size-3.5" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <InputError :message="serverError" />
            <p v-if="message" role="status" class="text-sm">{{ message }}</p>
            <div>
                <Button
                    type="button"
                    :disabled="busy || Object.keys(errors).length > 0"
                    @click="save"
                    >{{ t('Save') }}</Button
                >
            </div>
        </div>
    </div>
</template>
