<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import CustomLeadFields from '@/components/CustomLeadFields.vue';
const props = defineProps<{
    customFields: {
        id: number;
        key: string;
        name: string;
        type: string;
        options: string[] | null;
        required: boolean;
    }[];
    members: { id: number; name: string }[];
    lead: {
        id: number;
        first_name: string;
        last_name: string;
        email: string | null;
        phone: string | null;
        company: string | null;
        city: string | null;
        source: string | null;
        project_name: string | null;
        campaign_name: string | null;
        meta_form_id: string | null;
        meta_form_name: string | null;
        notes: string | null;
        custom_fields: Record<
            string,
            string | number | boolean | string[] | null
        >;
    };
}>();
const form = useForm({
    first_name: props.lead.first_name,
    last_name: props.lead.last_name,
    email: props.lead.email ?? '',
    phone: props.lead.phone ?? '',
    company: props.lead.company ?? '',
    city: props.lead.city ?? '',
    source: props.lead.source ?? '',
    project_name: props.lead.project_name ?? '',
    campaign_name: props.lead.campaign_name ?? '',
    meta_form_id: props.lead.meta_form_id ?? '',
    meta_form_name: props.lead.meta_form_name ?? '',
    notes: props.lead.notes ?? '',
    custom_fields: { ...props.lead.custom_fields },
});
const fields = [
    { value: 'first_name', label: 'First name', max: 100 },
    { value: 'last_name', label: 'Last name', max: 100 },
    { value: 'email', label: 'Email', max: 255 },
    { value: 'phone', label: 'Phone', max: 50 },
    { value: 'company', label: 'Company', max: 255 },
    { value: 'city', label: 'City', max: 255 },
    { value: 'source', label: 'Lead source', max: 100 },
    { value: 'project_name', label: 'Project', max: 255 },
    { value: 'campaign_name', label: 'Campaign', max: 255 },
    { value: 'meta_form_id', label: 'Meta form ID', max: 100 },
    { value: 'meta_form_name', label: 'Meta form name', max: 255 },
    { value: 'notes', label: 'Notes', max: 5000 },
] as const;
function save(): void {
    form.put(`/crm/leads/${props.lead.id}/details`, { preserveScroll: true });
}
</script>
<template>
    <form
        class="mt-3 grid gap-3 rounded-md border p-3 sm:grid-cols-2"
        @submit.prevent="save"
    >
        <div v-for="field in fields" :key="field.value">
            <Label :for="`details-${lead.id}-${field.value}`">{{
                field.label
            }}</Label
            ><Input
                :id="`details-${lead.id}-${field.value}`"
                v-model="form[field.value]"
                :type="field.value === 'email' ? 'email' : 'text'"
                :maxlength="field.max"
                :required="
                    field.value === 'first_name' || field.value === 'last_name'
                "
            />
        </div>
        <div class="sm:col-span-2" aria-live="polite">
            <InputError
                v-for="(error, field) in form.errors"
                :key="field"
                :message="error"
            />
        </div>
        <CustomLeadFields
            v-model="form.custom_fields"
            :fields="customFields"
            :members="members"
            :prefix="`details-${lead.id}`"
        />
        <Button class="w-fit" :disabled="form.processing"
            >Save lead details</Button
        >
    </form>
</template>
