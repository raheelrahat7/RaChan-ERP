<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import Money from '@/components/Money.vue';
import InputError from '@/components/InputError.vue';
import { useLocale } from '@/composables/useLocale';
import { priceRangeValid } from '@/lib/sales-crm-tools';

type SearchPreference = {
    purpose: 'sale' | 'rent';
    city: string | null;
    property_type: string | null;
    min_price_aed: string | null;
    max_price_aed: string | null;
} | null;
type Match = {
    listing_id: number;
    reference: string;
    property: string;
    city: string | null;
    unit: string;
    purpose: string;
    price_aed: number;
    reasons: string[];
};

const props = defineProps<{
    leadId: number;
    preference: SearchPreference;
    matches: Match[];
    mode: 'local_rules';
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'AI Matchmaker', href: '/crm/matchmaker' },
            { title: 'Lead', href: '#' },
        ],
    },
});

const { t } = useLocale();
const form = useForm({
    purpose: props.preference?.purpose ?? 'sale',
    city: props.preference?.city ?? '',
    property_type: props.preference?.property_type ?? '',
    min_price_aed: props.preference?.min_price_aed ?? '',
    max_price_aed: props.preference?.max_price_aed ?? '',
});
const rangeError = ref<string | null>(null);

function submit(): void {
    if (!priceRangeValid(form.min_price_aed, form.max_price_aed)) {
        rangeError.value = t(
            'The maximum price must be at least the minimum price.',
        );

        return;
    }
    rangeError.value = null;
    form.put(`/crm/matchmaker/leads/${props.leadId}/preference`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="t('AI Matchmaker')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Sales & CRM" title="Search preference & matches" />

        <div class="bg-card shadow-panel rounded-lg border p-5">
            <form class="grid grid-cols-2 gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1.5">
                    <Label>{{ t('Purpose') }}</Label>
                    <Select v-model="form.purpose">
                        <SelectTrigger class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="sale">{{
                                t('Sale')
                            }}</SelectItem>
                            <SelectItem value="rent">{{
                                t('Rent')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="pref-city"
                        >{{ t('City') }}
                        <span class="text-muted-foreground font-normal"
                            >({{ t('optional') }})</span
                        ></Label
                    >
                    <Input id="pref-city" v-model="form.city" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="pref-type"
                        >{{ t('Property type') }}
                        <span class="text-muted-foreground font-normal"
                            >({{ t('optional') }})</span
                        ></Label
                    >
                    <Input id="pref-type" v-model="form.property_type" />
                </div>
                <div class="col-span-2 grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <Label for="pref-min"
                            >{{ t('Minimum price (AED)') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input
                            id="pref-min"
                            v-model="form.min_price_aed"
                            type="number"
                            min="0"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="pref-max"
                            >{{ t('Maximum price (AED)') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input
                            id="pref-max"
                            v-model="form.max_price_aed"
                            type="number"
                            min="0"
                        />
                    </div>
                </div>
                <p
                    v-if="rangeError"
                    class="text-destructive col-span-2 text-xs"
                >
                    {{ rangeError }}
                </p>
                <InputError
                    class="col-span-2"
                    :message="form.errors.max_price_aed"
                />
                <div class="col-span-2 flex justify-end">
                    <Button type="submit" :disabled="form.processing">{{
                        t('Save preference')
                    }}</Button>
                </div>
            </form>
        </div>

        <div>
            <h2 class="font-display mb-3 text-2xl font-medium">
                {{ t('Matches') }}
            </h2>
            <p v-if="!preference" class="text-muted-foreground text-sm">
                {{ t('Set a search preference to see matches.') }}
            </p>
            <p
                v-else-if="matches.length === 0"
                class="text-muted-foreground text-sm"
            >
                {{ t('No active listings match this preference yet.') }}
            </p>
            <div v-else class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="match in matches"
                    :key="match.listing_id"
                    class="bg-card shadow-panel rounded-lg border p-4"
                >
                    <p class="font-medium">{{ match.reference }}</p>
                    <p class="text-muted-foreground text-sm">
                        {{ match.property }} · {{ match.city }} ·
                        {{ match.unit }}
                    </p>
                    <p class="font-display mt-1.5 text-lg">
                        <Money :value="match.price_aed" />
                    </p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span
                            v-for="reason in match.reasons"
                            :key="reason"
                            class="bg-success/10 text-success rounded-full px-2 py-0.5 text-[11px]"
                            >{{ t(reason) }}</span
                        >
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
