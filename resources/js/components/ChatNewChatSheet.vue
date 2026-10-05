<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocale } from '@/composables/useLocale';
import type { ChatMember } from '@/lib/chat-view';

const props = defineProps<{ members: ChatMember[]; currentUserId: number }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{
    direct: [userId: number];
    group: [name: string, members: number[]];
}>();
const { t } = useLocale();
const mode = ref<'direct' | 'group'>('direct');
const recipient = ref<number | null>(null);
const name = ref('');
const picked = ref<number[]>([]);
const others = () =>
    props.members.filter((member) => member.id !== props.currentUserId);

function toggle(id: number): void {
    picked.value = picked.value.includes(id)
        ? picked.value.filter((item) => item !== id)
        : [...picked.value, id];
}
function submit(): void {
    if (mode.value === 'direct' && recipient.value) {
        emit('direct', recipient.value);
    } else if (
        mode.value === 'group' &&
        name.value.trim() &&
        picked.value.length >= 2
    ) {
        emit('group', name.value.trim(), picked.value);
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
            <SheetHeader class="border-b"
                ><SheetTitle class="font-display text-2xl font-medium">{{
                    t('Start a chat')
                }}</SheetTitle
                ><SheetDescription>{{
                    t('Message one person or create a group.')
                }}</SheetDescription></SheetHeader
            >
            <div class="flex gap-2 border-b p-4" role="tablist">
                <Button
                    type="button"
                    size="sm"
                    role="tab"
                    :aria-selected="mode === 'direct'"
                    :variant="mode === 'direct' ? 'default' : 'outline'"
                    @click="mode = 'direct'"
                    >{{ t('Direct message') }}</Button
                ><Button
                    type="button"
                    size="sm"
                    role="tab"
                    :aria-selected="mode === 'group'"
                    :variant="mode === 'group' ? 'default' : 'outline'"
                    @click="mode = 'group'"
                    >{{ t('Group') }}</Button
                >
            </div>
            <div class="flex-1 space-y-3 overflow-y-auto p-4">
                <label
                    v-if="mode === 'direct'"
                    class="block space-y-1 text-sm font-medium"
                    >{{ t('Person') }}
                    <select
                        v-model="recipient"
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm font-normal"
                    >
                        <option :value="null">
                            {{ t('Choose a member') }}
                        </option>
                        <option
                            v-for="member in others()"
                            :key="member.id"
                            :value="member.id"
                        >
                            {{ member.name }}
                        </option>
                    </select>
                </label>
                <template v-else>
                    <label class="block space-y-1 text-sm font-medium"
                        >{{ t('Group name')
                        }}<Input v-model="name" maxlength="120"
                    /></label>
                    <p class="text-muted-foreground text-xs">
                        {{ t('Select at least two members') }}
                    </p>
                    <label
                        v-for="member in others()"
                        :key="member.id"
                        class="hover:bg-muted flex items-center gap-2 rounded px-2 py-1.5 text-sm"
                        ><input
                            type="checkbox"
                            :checked="picked.includes(member.id)"
                            @change="toggle(member.id)"
                        />{{ member.name }}</label
                    >
                </template>
            </div>
            <SheetFooter
                class="bg-background flex-row justify-end gap-2 border-t p-4"
                ><Button
                    type="button"
                    variant="outline"
                    @click="open = false"
                    >{{ t('Cancel') }}</Button
                ><Button
                    type="button"
                    :disabled="
                        mode === 'direct'
                            ? !recipient
                            : !name.trim() || picked.length < 2
                    "
                    @click="submit"
                    >{{
                        mode === 'direct'
                            ? t('Open direct chat')
                            : t('Create group')
                    }}</Button
                ></SheetFooter
            >
        </SheetContent>
    </Sheet>
</template>
