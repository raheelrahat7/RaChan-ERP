<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Listing = {
    reference: string;
    purpose: string;
    price: string;
    currency: string;
    unit: {
        number: string;
        type: string;
        area: string | null;
        area_unit: string;
    } | null;
    property: { name: string; city: string | null } | null;
};
defineProps<{ listing: Listing }>();
const submitted = ref(false);
const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    notes: '',
    website: '',
});
function submit(): void {
    form.post(window.location.pathname + '/inquiries', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            submitted.value = true;
        },
    });
}
</script>

<template>
    <Head :title="`${listing.property?.name || 'Property'} listing`" />
    <main class="bg-muted/30 min-h-screen px-4 py-10">
        <div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-2">
            <section class="space-y-5 py-4">
                <p
                    class="text-primary text-sm font-medium tracking-wide uppercase"
                >
                    {{ listing.purpose === 'rent' ? 'For rent' : 'For sale' }}
                </p>
                <h1 class="text-4xl font-semibold tracking-tight">
                    {{ listing.property?.name || 'Property listing' }}
                </h1>
                <p class="text-muted-foreground text-lg">
                    {{
                        listing.property?.city ||
                        'Location available on request'
                    }}
                    ·
                    {{ listing.unit?.type || 'Unit' }}
                </p>
                <p class="text-3xl font-semibold">
                    {{ listing.currency }} {{ listing.price }}
                </p>
                <dl
                    class="bg-background grid grid-cols-2 gap-4 rounded-lg border p-5"
                >
                    <div>
                        <dt class="text-muted-foreground text-sm">
                            {{ t('Reference') }}
                        </dt>
                        <dd>{{ listing.reference }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-sm">Unit type</dt>
                        <dd class="capitalize">
                            {{ listing.unit?.type || 'Available on request' }}
                        </dd>
                    </div>
                    <div v-if="listing.unit?.area">
                        <dt class="text-muted-foreground text-sm">Area</dt>
                        <dd>
                            {{ listing.unit.area }}
                            {{ listing.unit.area_unit.replace('_', ' ') }}
                        </dd>
                    </div>
                </dl>
            </section>
            <Card>
                <CardHeader
                    ><CardTitle
                        >Request details or a viewing</CardTitle
                    ></CardHeader
                >
                <CardContent>
                    <p
                        v-if="submitted"
                        role="status"
                        class="rounded-md border border-green-600/30 bg-green-50 p-3 text-sm text-green-800"
                    >
                        Thank you. Your inquiry has been received.
                    </p>
                    <form
                        v-else
                        class="grid gap-4 sm:grid-cols-2"
                        @submit.prevent="submit"
                    >
                        <label class="space-y-1 text-sm"
                            >First name<Input
                                v-model="form.first_name"
                                autocomplete="given-name"
                                required
                        /></label>
                        <label class="space-y-1 text-sm"
                            >Last name<Input
                                v-model="form.last_name"
                                autocomplete="family-name"
                                required
                        /></label>
                        <label class="space-y-1 text-sm"
                            >{{ t('Email')
                            }}<Input
                                v-model="form.email"
                                type="email"
                                autocomplete="email"
                        /></label>
                        <label class="space-y-1 text-sm"
                            >{{ t('Phone')
                            }}<Input v-model="form.phone" autocomplete="tel"
                        /></label>
                        <label class="hidden" aria-hidden="true"
                            >Website<Input
                                v-model="form.website"
                                tabindex="-1"
                                autocomplete="off"
                        /></label>
                        <label class="space-y-1 text-sm sm:col-span-2"
                            >Message<Input
                                v-model="form.notes"
                                placeholder="Preferred viewing time or questions"
                        /></label>
                        <p
                            v-if="Object.keys(form.errors).length"
                            role="alert"
                            class="text-destructive text-sm sm:col-span-2"
                        >
                            {{ Object.values(form.errors).join(' ') }}
                        </p>
                        <Button
                            class="sm:col-span-2"
                            :disabled="form.processing"
                            >Send inquiry</Button
                        >
                    </form>
                </CardContent>
            </Card>
        </div>
    </main>
</template>
