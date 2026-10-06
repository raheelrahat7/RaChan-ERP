<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import CrmCustomFieldsForm from '@/components/CrmCustomFieldsForm.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import {
    companyBody,
    contactBody,
    fieldDraft,
    fieldPayload,
} from '@/lib/crm-parties';
import type {
    FieldDraft,
    PartyCompany,
    PartyContact,
    PartyField,
} from '@/lib/crm-parties';

const props = defineProps<{
    kind: 'contact' | 'company';
    contact?: PartyContact | null;
    company?: PartyCompany | null;
    fields: PartyField[];
    companies?: { id: number; name: string }[];
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const form = ref({
    first_name: '',
    last_name: '',
    name: '',
    email: '',
    phone: '',
    website: '',
    account_id: null as number | null,
});
const draft = ref<FieldDraft>({});
const errors = ref<Record<string, string>>({});
const processing = ref(false);
const title = computed(() =>
    props.kind === 'contact' ? t('Edit contact') : t('Edit company'),
);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }
    errors.value = {};
    const source = props.kind === 'contact' ? props.contact : props.company;
    form.value = {
        first_name: props.contact?.first_name ?? '',
        last_name: props.contact?.last_name ?? '',
        name: props.company?.name ?? '',
        email: source?.email ?? '',
        phone: source?.phone ?? '',
        website: props.company?.website ?? '',
        account_id:
            props.contact?.account_id ?? props.contact?.account?.id ?? null,
    };
    draft.value = fieldDraft(props.fields);
});

async function save(): Promise<void> {
    const record = props.kind === 'contact' ? props.contact : props.company;
    if (!record) {
        return;
    }
    processing.value = true;
    errors.value = {};
    const custom = fieldPayload(props.fields, draft.value);
    try {
        if (props.kind === 'contact') {
            await apiJson(
                `/crm/contacts/${record.id}`,
                'PUT',
                contactBody(form.value, record.version, custom),
            );
        } else {
            await apiJson(
                `/crm/companies/${record.id}`,
                'PUT',
                companyBody(form.value, record.version, custom),
            );
        }
        open.value = false;
        emit('saved');
    } catch (failure) {
        if (failure instanceof ApiError) {
            const found = failure.fieldErrors();
            errors.value = Object.keys(found).length
                ? found
                : { form: failure.message };
        } else {
            errors.value = {
                form: t('Something went wrong. Please try again.'),
            };
        }
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">{{
                    title
                }}</SheetTitle>
                <SheetDescription>{{
                    t(
                        'Changes are checked against the latest version of the record.',
                    )
                }}</SheetDescription>
            </SheetHeader>
            <form
                id="party-form"
                class="flex-1 space-y-4 overflow-y-auto p-4"
                @submit.prevent="save"
            >
                <InputError :message="errors.form" />
                <InputError :message="errors.expected_version" />
                <template v-if="kind === 'contact'">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="pf-first">{{ t('First name') }}</Label
                            ><Input
                                id="pf-first"
                                v-model="form.first_name"
                                required
                            /><InputError :message="errors.first_name" />
                        </div>
                        <div class="space-y-1">
                            <Label for="pf-last">{{ t('Last name') }}</Label
                            ><Input
                                id="pf-last"
                                v-model="form.last_name"
                                required
                            /><InputError :message="errors.last_name" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="pf-company">{{ t('Company') }}</Label>
                        <select
                            id="pf-company"
                            v-model="form.account_id"
                            :class="selectClass"
                        >
                            <option :value="null">—</option>
                            <option
                                v-for="item in companies ?? []"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                        <InputError :message="errors.account_id" />
                    </div>
                </template>
                <div v-else class="space-y-1">
                    <Label for="pf-name">{{ t('Name') }}</Label
                    ><Input
                        id="pf-name"
                        v-model="form.name"
                        required
                    /><InputError :message="errors.name" />
                </div>
                <div class="space-y-1">
                    <Label for="pf-email">{{ t('Email') }}</Label
                    ><Input
                        id="pf-email"
                        v-model="form.email"
                        type="email"
                    /><InputError :message="errors.email" />
                </div>
                <div class="space-y-1">
                    <Label for="pf-phone">{{ t('Phone') }}</Label
                    ><Input id="pf-phone" v-model="form.phone" /><InputError
                        :message="errors.phone"
                    />
                </div>
                <div v-if="kind === 'company'" class="space-y-1">
                    <Label for="pf-web">{{ t('Website') }}</Label
                    ><Input
                        id="pf-web"
                        v-model="form.website"
                        type="url"
                        placeholder="https://"
                    /><InputError :message="errors.website" />
                </div>
                <h3 class="text-eyebrow pt-2">{{ t('Custom fields') }}</h3>
                <CrmCustomFieldsForm
                    v-model="draft"
                    :fields="fields"
                    :errors="errors"
                />
            </form>
            <SheetFooter class="border-t">
                <Button
                    type="submit"
                    form="party-form"
                    :disabled="processing"
                    >{{ t('Save') }}</Button
                >
                <Button type="button" variant="outline" @click="open = false">{{
                    t('Cancel')
                }}</Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
