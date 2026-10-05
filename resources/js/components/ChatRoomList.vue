<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AtSign, MessagesSquare, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import {
    attachmentLabel,
    initials,
    roomSubtitle,
    unreadLabel,
} from '@/lib/chat-view';
import type { ChatMember } from '@/lib/chat-view';

export type ChatRoom = {
    id: number;
    kind: 'workspace' | 'direct' | 'group';
    name: string;
    member_count: number;
    unread: number;
    mentioned: boolean;
    members: ChatMember[];
    last_message: {
        id: number;
        sender: string;
        own: boolean;
        body: string;
        attachment_kind: string | null;
        created_at: string | null;
    } | null;
};

const props = defineProps<{
    rooms: ChatRoom[];
    activeRoomId: number;
    members: ChatMember[];
    currentUserId: number;
}>();
const emit = defineEmits<{ startDirect: [userId: number] }>();
const { t } = useLocale();
const query = ref('');

const ordered = computed(() =>
    [...props.rooms].sort(
        (a, b) =>
            Number(b.kind === 'workspace') - Number(a.kind === 'workspace'),
    ),
);
const visible = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return ordered.value.filter(
        (room) => !needle || room.name.toLowerCase().includes(needle),
    );
});
const suggestions = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) {
        return [];
    }
    const direct = new Set(
        props.rooms
            .filter((room) => room.kind === 'direct')
            .map((room) => room.name),
    );

    return props.members
        .filter(
            (member) =>
                member.id !== props.currentUserId &&
                member.name.toLowerCase().includes(needle) &&
                !direct.has(member.name),
        )
        .slice(0, 5);
});

function preview(room: ChatRoom): string {
    const last = room.last_message;
    if (!last) {
        return t(roomSubtitle(room.kind, room.member_count));
    }
    const text = last.body.trim() || t(attachmentLabel(last.attachment_kind));

    return `${last.own ? `${t('You')}: ` : room.kind === 'direct' ? '' : `${last.sender}: `}${text}`;
}
function when(value: string | null | undefined): string {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    const today = new Date();

    return date.toDateString() === today.toDateString()
        ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : date.toLocaleDateString([], { month: 'short', day: 'numeric' });
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <div class="border-b p-3">
            <div class="relative">
                <Search
                    class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    v-model="query"
                    type="search"
                    class="ps-9"
                    :aria-label="t('Find employee or chat')"
                    :placeholder="t('Find employee or chat')"
                />
            </div>
        </div>
        <ul
            class="min-h-0 flex-1 overflow-y-auto"
            :aria-label="t('Conversations')"
        >
            <li v-for="room in visible" :key="room.id">
                <Link
                    :href="`/chat?room=${room.id}`"
                    class="hover:bg-muted/60 flex gap-3 border-b px-3 py-3"
                    :class="room.id === activeRoomId ? 'bg-primary/10' : ''"
                    :aria-current="
                        room.id === activeRoomId ? 'true' : undefined
                    "
                >
                    <span
                        class="bg-primary/15 text-primary flex size-11 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                    >
                        <MessagesSquare
                            v-if="room.kind === 'workspace'"
                            class="size-5"
                            aria-hidden="true"
                        /><template v-else>{{ initials(room.name) }}</template>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-baseline justify-between gap-2">
                            <span
                                class="truncate text-sm"
                                :class="
                                    room.unread
                                        ? 'font-semibold'
                                        : 'font-medium'
                                "
                                >{{ room.name }}</span
                            >
                            <span
                                class="text-muted-foreground shrink-0 text-xs"
                                >{{ when(room.last_message?.created_at) }}</span
                            >
                        </span>
                        <span
                            class="mt-0.5 flex items-center justify-between gap-2"
                        >
                            <span
                                class="text-muted-foreground truncate text-xs"
                                >{{ preview(room) }}</span
                            >
                            <span
                                v-if="room.unread"
                                class="bg-primary text-primary-foreground inline-flex h-5 min-w-5 shrink-0 items-center justify-center gap-0.5 rounded-full px-1.5 text-[11px] font-semibold"
                                :aria-label="`${room.unread} ${t('unread')}`"
                            >
                                <AtSign
                                    v-if="room.mentioned"
                                    class="size-3"
                                    aria-hidden="true"
                                />{{ unreadLabel(room.unread) }}
                            </span>
                        </span>
                    </span>
                </Link>
            </li>
            <li v-for="member in suggestions" :key="`member-${member.id}`">
                <button
                    type="button"
                    class="hover:bg-muted/60 flex w-full items-center gap-3 border-b px-3 py-3 text-start"
                    @click="emit('startDirect', member.id)"
                >
                    <span
                        class="bg-muted text-muted-foreground flex size-11 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                        >{{ initials(member.name) }}</span
                    >
                    <span class="min-w-0"
                        ><span class="block truncate text-sm font-medium">{{
                            member.name
                        }}</span
                        ><span class="text-muted-foreground block text-xs">{{
                            t('Start a direct chat')
                        }}</span></span
                    >
                </button>
            </li>
            <li
                v-if="!visible.length && !suggestions.length"
                class="text-muted-foreground p-6 text-center text-sm"
            >
                {{ t('No chats match your search.') }}
            </li>
        </ul>
    </div>
</template>
