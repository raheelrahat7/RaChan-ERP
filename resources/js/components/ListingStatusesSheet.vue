<script setup lang="ts">
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import { codeFromName } from '@/lib/listings';
import type { WorkflowStatus } from '@/lib/listings';

const props = defineProps<{ statuses: WorkflowStatus[] }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ changed: [] }>();
const { t } = useLocale();

type Draft = { name: string; position: string; active: boolean };
const drafts = ref<Record<string, Draft>>({});
const added = ref({ name: '', code: '' });
const errors = ref<Record<string, string>>({});
const message = ref('');
const busy = ref('');

function load(): void {
    errors.value = {};
    drafts.value = Object.fromEntries(
        props.statuses.map((status) => [
            status.code,
            {
                name: status.name,
                position: String(status.position),
                active: status.active,
            },
        ]),
    );
}
watch(open, (isOpen) => isOpen && load());
watch(() => props.statuses, load);

function fail(code: string, error: unknown): void {
    errors.value = {
        [code]:
            error instanceof ApiError
                ? (Object.values(error.fieldErrors())[0] ?? error.message)
                : t('Could not save.'),
    };
}

async function save(status: WorkflowStatus): Promise<void> {
    const draft = drafts.value[status.code];
    busy.value = status.code;
    errors.value = {};
    message.value = '';
    try {
        const body = {
            code: status.code,
            name: draft.name.trim(),
            position: Number(draft.position) || 0,
            active: draft.active,
        };
        // A built-in status has no record yet; posting its code customises it once.
        if (status.id === null) {
            await apiJson(
                '/real-estate/listings/workflow-statuses',
                'POST',
                body,
            );
        } else {
            await apiJson(
                `/real-estate/listings/workflow-statuses/${status.id}`,
                'PUT',
                { ...body, expected_version: status.version },
            );
        }
        message.value = t('Saved.');
        emit('changed');
    } catch (error) {
        fail(status.code, error);
    } finally {
        busy.value = '';
    }
}

async function add(): Promise<void> {
    const code = added.value.code || codeFromName(added.value.name);
    busy.value = 'new';
    errors.value = {};
    message.value = '';
    try {
        await apiJson('/real-estate/listings/workflow-statuses', 'POST', {
            code,
            name: added.value.name.trim(),
            position: props.statuses.length,
            active: true,
        });
        added.value = { name: '', code: '' };
        message.value = t('Saved.');
        emit('changed');
    } catch (error) {
        fail('new', error);
    } finally {
        busy.value = '';
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-xl" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">{{
                    t('Listing workflow statuses')
                }}</SheetTitle>
                <SheetDescription>{{
                    t(
                        'Display labels for the status strip. They never publish a listing or change finance records. Archive a status to stop new use.',
                    )
                }}</SheetDescription>
            </SheetHeader>
            <div class="flex-1 space-y-3 overflow-y-auto p-4">
                <p v-if="message" role="status" class="text-sm">
                    {{ message }}
                </p>
                <div
                    v-for="status in statuses"
                    :key="status.code"
                    class="space-y-1 rounded-md border p-3"
                >
                    <div class="grid grid-cols-[1fr_5rem] gap-2">
                        <div class="space-y-1">
                            <Label :for="`ws-${status.code}`">{{
                                status.code
                            }}</Label>
                            <Input
                                :id="`ws-${status.code}`"
                                v-model="drafts[status.code].name"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label :for="`wp-${status.code}`">{{
                                t('Order')
                            }}</Label>
                            <Input
                                :id="`wp-${status.code}`"
                                v-model="drafts[status.code].position"
                                type="number"
                                min="0"
                            />
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="drafts[status.code].active"
                                type="checkbox"
                            />{{ t('Active') }}</label
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="busy !== ''"
                            @click="save(status)"
                            >{{ t('Save') }}</Button
                        >
                    </div>
                    <InputError :message="errors[status.code]" />
                </div>
                <div class="space-y-2 rounded-md border border-dashed p-3">
                    <p class="text-sm font-medium">{{ t('Add status') }}</p>
                    <Input
                        v-model="added.name"
                        :placeholder="t('Name')"
                        :aria-label="t('Name')"
                    />
                    <Button
                        type="button"
                        size="sm"
                        :disabled="busy !== '' || !added.name.trim()"
                        @click="add"
                        >{{ t('Add status') }}</Button
                    >
                    <InputError :message="errors.new" />
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
