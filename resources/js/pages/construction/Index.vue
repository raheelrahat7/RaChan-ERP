<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
defineProps<{
    properties: { id: number; name: string }[];
    projects: {
        data: {
            id: number;
            reference: string;
            title: string;
            status: string;
            budget: string;
        }[];
        links: { label: string; url: string | null; active: boolean }[];
    };
}>();
const form = useForm({ property_id: '', reference: '', title: '', budget: '' });
function create(): void {
    form.post('/operations/projects');
}
</script>
<template>
    <Head title="Construction projects" />
    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
        <Heading
            title="Construction projects"
            description="Track gross budgets, BOQ progress and owner-approved contractor claims in AED."
        /><Link href="/maintenance" class="text-sm underline">{{
            t('Maintenance')
        }}</Link
        ><Card
            ><CardHeader><CardTitle>Create project</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="create"
                >
                    <label class="text-sm"
                        >{{ t('Reference')
                        }}<Input
                            v-model="form.reference"
                            required
                            maxlength="100" /></label
                    ><label class="text-sm"
                        >{{ t('Title')
                        }}<Input
                            v-model="form.title"
                            required
                            maxlength="255" /></label
                    ><label class="text-sm"
                        >Gross budget AED<Input
                            v-model="form.budget"
                            required
                            inputmode="decimal" /></label
                    ><label class="text-sm"
                        >Property (optional)<select
                            v-model="form.property_id"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">No property allocation</option>
                            <option
                                v-for="property in properties"
                                :key="property.id"
                                :value="String(property.id)"
                            >
                                {{ property.name }}
                            </option>
                        </select></label
                    >
                    <p
                        v-for="(message, field) in form.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="form.processing"
                        >Create project</Button
                    >
                </form></CardContent
            ></Card
        ><Card
            ><CardHeader><CardTitle>Projects</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!projects.data.length">No projects recorded.</p>
                <Link
                    v-for="project in projects.data"
                    :key="project.id"
                    :href="`/operations/projects/${project.id}`"
                    class="block rounded-md border p-3 text-sm underline"
                    >{{ project.reference }} · {{ project.title }} ·
                    {{ project.status }} · Budget AED {{ project.budget }}</Link
                ><Pagination :links="projects.links" /></CardContent
        ></Card>
    </div>
</template>
