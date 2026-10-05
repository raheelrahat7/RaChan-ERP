<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Phone,
    Search,
    SquarePen,
    Users,
    Video,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ChatComposer from '@/components/ChatComposer.vue';
import ChatMessageList from '@/components/ChatMessageList.vue';
import ChatNewChatSheet from '@/components/ChatNewChatSheet.vue';
import ChatRoomList from '@/components/ChatRoomList.vue';
import type { ChatRoom } from '@/components/ChatRoomList.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { initials, roomSubtitle } from '@/lib/chat-view';
import type { ChatMember, ChatMessage } from '@/lib/chat-view';

type Call = {
    id: number;
    room_id: number;
    initiator_id: number;
    recipient_id: number;
    status: 'ringing' | 'active';
    kind: 'audio' | 'video';
};
type Signal = {
    id: number;
    type: 'offer' | 'answer' | 'ice';
    payload: RTCSessionDescriptionInit | RTCIceCandidateInit;
};
type ViewedBy = { message_id: number; users: ChatMember[] } | null;
const props = defineProps<{
    rooms: ChatRoom[];
    activeRoomId: number;
    messages: ChatMessage[];
    viewedBy: ViewedBy;
    members: ChatMember[];
    currentUserId: number;
    canExportChats: boolean;
    exportRooms: { id: number; name: string }[];
    iceServers: RTCIceServer[];
}>();
const { t } = useLocale();
const roomList = ref<ChatRoom[]>([...props.rooms]);
const room = computed(() =>
    roomList.value.find((item) => item.id === props.activeRoomId),
);
const messages = ref<ChatMessage[]>([...props.messages]);
const viewedBy = ref<ViewedBy>(props.viewedBy);
const body = ref('');
const exportRoomId = ref<number | null>(null);
const error = ref('');
const sending = ref(false);
const list = ref<InstanceType<typeof ChatMessageList> | null>(null);
const remoteMedia = ref<HTMLVideoElement | null>(null);
const localPreview = ref<HTMLVideoElement | null>(null);
const call = ref<Call | null>(null);
const calling = ref(false);
const answering = ref(false);
const newChatOpen = ref(false);
const mobileList = ref(false);
const membersOpen = ref(false);
const searchOpen = ref(false);
const searchQuery = ref('');
const searchResults = ref<ChatMessage[] | null>(null);
let peer: RTCPeerConnection | null = null;
let localStream: MediaStream | null = null;
let messagesTimer: number | null = null;
let roomsTimer: number | null = null;
let callTimer: number | null = null;
let signalsTimer: number | null = null;
let searchTimer: number | null = null;
let lastSignalId = 0;
let lastMarked = 0;
const pendingIce: RTCIceCandidateInit[] = [];

const participants = computed<ChatMember[]>(() =>
    room.value?.kind === 'workspace'
        ? props.members
        : (room.value?.members ?? []),
);
const mentionable = computed(() =>
    participants.value.filter((member) => member.id !== props.currentUserId),
);
const shown = computed(() => searchResults.value ?? messages.value);
const subtitle = computed(() =>
    room.value ? t(roomSubtitle(room.value.kind, room.value.member_count)) : '',
);
const inVideo = computed(
    () =>
        call.value?.kind === 'video' &&
        (calling.value || answering.value || call.value.status === 'active'),
);

function xsrf(): string {
    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}
async function json<T>(
    path: string,
    method = 'GET',
    data?: unknown,
): Promise<T> {
    const options: RequestInit = {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
    };
    if (data !== undefined) options.body = JSON.stringify(data);
    const response = await fetch(path, options);
    if (!response.ok) {
        const details = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        throw new Error(
            Object.values(details.errors ?? {}).flat()[0] ??
                details.message ??
                `Request failed (${response.status}).`,
        );
    }
    return response.json() as Promise<T>;
}
async function upload<T>(path: string, form: FormData): Promise<T> {
    const response = await fetch(path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: form,
    });
    if (!response.ok) {
        const details = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        throw new Error(
            Object.values(details.errors ?? {}).flat()[0] ??
                details.message ??
                `Upload failed (${response.status}).`,
        );
    }

    return response.json() as Promise<T>;
}
function scrollBottom(): Promise<void> | undefined {
    return list.value?.scrollToBottom();
}
async function markRead(): Promise<void> {
    const last = messages.value.at(-1)?.id ?? 0;
    if (!last || last <= lastMarked || document.hidden) return;
    lastMarked = last;
    const active = roomList.value.find(
        (item) => item.id === props.activeRoomId,
    );
    if (active) {
        active.unread = 0;
        active.mentioned = false;
    }
    try {
        await json(`/chat/rooms/${props.activeRoomId}/read`, 'POST', {
            message_id: last,
        });
    } catch {
        lastMarked = 0;
    }
}
async function pollMessages(): Promise<void> {
    try {
        const after = messages.value.at(-1)?.id ?? 0;
        const result = await json<{
            messages: ChatMessage[];
            viewed_by: ViewedBy;
        }>(`/chat/rooms/${props.activeRoomId}/messages?after=${after}`);
        viewedBy.value = result.viewed_by;
        if (result.messages.length) {
            messages.value.push(
                ...result.messages.filter(
                    (item) =>
                        !messages.value.some(
                            (existing) => existing.id === item.id,
                        ),
                ),
            );
            await scrollBottom();
            await markRead();
        }
    } catch {
        /* Retain local messages during a transient connection loss. */
    }
}
async function older(): Promise<void> {
    const before = messages.value[0]?.id;
    if (!before) return;
    try {
        const result = await json<{ messages: ChatMessage[] }>(
            `/chat/rooms/${props.activeRoomId}/messages?before=${before}`,
        );
        messages.value.unshift(...result.messages);
    } catch (cause) {
        error.value = (cause as Error).message;
    }
}
function mentionList(ids: number[]): number[] {
    return ids.filter((id) => id !== props.currentUserId);
}
async function send(text: string, mentions: number[]): Promise<void> {
    if (sending.value) return;
    sending.value = true;
    error.value = '';
    try {
        const result = await json<{ message: ChatMessage }>(
            `/chat/rooms/${props.activeRoomId}/messages`,
            'POST',
            { body: text, mentions: mentionList(mentions) },
        );
        body.value = '';
        messages.value.push(result.message);
        await scrollBottom();
        await markRead();
    } catch (cause) {
        error.value = (cause as Error).message;
    } finally {
        sending.value = false;
    }
}
async function attach(files: File[]): Promise<void> {
    if (sending.value) return;
    if (files.length > 5) {
        error.value = t('You can send up to 5 files at a time.');
        return;
    }
    if (files.some((file) => file.size > 10 * 1024 * 1024)) {
        error.value = t('Each file can be up to 10 MB.');
        return;
    }
    await sendFiles(files, false, null);
}
async function sendVoice(file: File, seconds: number): Promise<void> {
    await sendFiles([file], true, seconds);
}
async function sendFiles(
    files: File[],
    voice: boolean,
    duration: number | null,
): Promise<void> {
    sending.value = true;
    error.value = '';
    try {
        const form = new FormData();
        files.forEach((file) => form.append('files[]', file));
        if (!voice && body.value.trim()) {
            form.append('body', body.value.trim());
            mentionList(
                mentionable.value
                    .filter((member) => body.value.includes(`@${member.name}`))
                    .map((member) => member.id),
            ).forEach((id) => form.append('mentions[]', String(id)));
        }
        if (voice) {
            form.append('voice', '1');
            form.append('duration', String(duration ?? 1));
        }
        const result = await upload<{ message: ChatMessage }>(
            `/chat/rooms/${props.activeRoomId}/attachments`,
            form,
        );
        if (!voice) body.value = '';
        messages.value.push(result.message);
        await scrollBottom();
        await markRead();
    } catch (cause) {
        error.value = (cause as Error).message;
    } finally {
        sending.value = false;
    }
}
async function startDirect(userId: number): Promise<void> {
    try {
        const result = await json<{ room_id: number }>('/chat/direct', 'POST', {
            recipient_id: userId,
        });
        window.location.assign(`/chat?room=${result.room_id}`);
    } catch (cause) {
        error.value = (cause as Error).message;
    }
}
async function createGroup(name: string, members: number[]): Promise<void> {
    try {
        const result = await json<{ room_id: number }>('/chat/group', 'POST', {
            name,
            members,
        });
        window.location.assign(`/chat?room=${result.room_id}`);
    } catch (cause) {
        error.value = (cause as Error).message;
    }
}
function toggleSearch(): void {
    searchOpen.value = !searchOpen.value;
    if (!searchOpen.value) {
        searchQuery.value = '';
        searchResults.value = null;
    }
}
function closeMedia(): void {
    peer?.close();
    peer = null;
    localStream?.getTracks().forEach((track) => track.stop());
    localStream = null;
    if (remoteMedia.value) remoteMedia.value.srcObject = null;
    if (localPreview.value) localPreview.value.srcObject = null;
    pendingIce.length = 0;
    calling.value = false;
    answering.value = false;
}
async function signal(
    type: Signal['type'],
    payload: RTCSessionDescriptionInit | RTCIceCandidateInit,
): Promise<void> {
    if (!call.value) return;
    await json(`/chat/calls/${call.value.id}/signals`, 'POST', {
        type,
        payload,
    });
}
async function setupPeer(kind: 'audio' | 'video'): Promise<void> {
    localStream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: kind === 'video',
    });
    if (localPreview.value && kind === 'video')
        localPreview.value.srcObject = localStream;
    peer = new RTCPeerConnection({ iceServers: props.iceServers });
    localStream
        .getTracks()
        .forEach((track) => peer?.addTrack(track, localStream!));
    peer.onicecandidate = (event) => {
        if (event.candidate)
            void signal('ice', event.candidate.toJSON()).catch((cause) => {
                error.value = (cause as Error).message;
            });
    };
    peer.ontrack = (event) => {
        if (remoteMedia.value) {
            remoteMedia.value.srcObject = event.streams[0];
            void remoteMedia.value.play().catch(() => {});
        }
    };
    peer.onconnectionstatechange = () => {
        if (peer?.connectionState === 'failed')
            error.value = t(
                'The call connection failed. A TURN server may be required for these networks.',
            );
    };
}
async function startCall(kind: 'audio' | 'video'): Promise<void> {
    error.value = '';
    try {
        const result = await json<{ call: Call }>(
            `/chat/rooms/${props.activeRoomId}/call`,
            'POST',
            { kind },
        );
        call.value = result.call;
        await setupPeer(kind);
        calling.value = true;
        lastSignalId = 0;
        const offer = await peer!.createOffer();
        await peer!.setLocalDescription(offer);
        await signal('offer', offer);
    } catch (cause) {
        const started = call.value;
        closeMedia();
        if (started) {
            await json(`/chat/calls/${started.id}/end`, 'POST').catch(() => {});
            call.value = null;
        }
        error.value = (cause as Error).message;
    }
}
async function answerCall(): Promise<void> {
    error.value = '';
    try {
        await setupPeer(call.value?.kind ?? 'audio');
        answering.value = true;
        await pollSignals();
    } catch (cause) {
        closeMedia();
        error.value = (cause as Error).message;
    }
}
async function flushIce(): Promise<void> {
    if (!peer?.remoteDescription) return;
    while (pendingIce.length) await peer.addIceCandidate(pendingIce.shift()!);
}
async function pollSignals(): Promise<void> {
    if (!call.value || !peer) return;
    try {
        const result = await json<{ signals: Signal[] }>(
            `/chat/calls/${call.value.id}/signals?after=${lastSignalId}`,
        );
        for (const item of result.signals) {
            lastSignalId = Math.max(lastSignalId, item.id);
            if (
                item.type === 'offer' &&
                answering.value &&
                !peer.remoteDescription
            ) {
                await peer.setRemoteDescription(
                    item.payload as RTCSessionDescriptionInit,
                );
                await flushIce();
                const answer = await peer.createAnswer();
                await peer.setLocalDescription(answer);
                await signal('answer', answer);
            } else if (
                item.type === 'answer' &&
                calling.value &&
                !peer.remoteDescription
            ) {
                await peer.setRemoteDescription(
                    item.payload as RTCSessionDescriptionInit,
                );
                await flushIce();
            } else if (item.type === 'ice') {
                if (peer.remoteDescription)
                    await peer.addIceCandidate(
                        item.payload as RTCIceCandidateInit,
                    );
                else pendingIce.push(item.payload as RTCIceCandidateInit);
            }
        }
    } catch (cause) {
        error.value = (cause as Error).message;
    }
}
async function pollCall(): Promise<void> {
    if (room.value?.kind !== 'direct') return;
    try {
        const result = await json<{ call: Call | null }>(
            `/chat/rooms/${props.activeRoomId}/call`,
        );
        if (!result.call && call.value) closeMedia();
        if (result.call?.id !== call.value?.id) lastSignalId = 0;
        call.value = result.call;
    } catch {
        /* Keep the active call visible during a transient connection loss. */
    }
}
async function endCall(): Promise<void> {
    if (call.value) {
        try {
            await json(`/chat/calls/${call.value.id}/end`, 'POST');
        } catch (cause) {
            error.value = (cause as Error).message;
        }
    }
    call.value = null;
    closeMedia();
}
watch(searchQuery, (value) => {
    if (searchTimer) clearTimeout(searchTimer);
    if (!value.trim()) {
        searchResults.value = null;
        return;
    }
    searchTimer = window.setTimeout(async () => {
        try {
            const result = await json<{ messages: ChatMessage[] }>(
                `/chat/rooms/${props.activeRoomId}/messages?q=${encodeURIComponent(value.trim())}`,
            );
            searchResults.value = result.messages;
        } catch (cause) {
            error.value = (cause as Error).message;
        }
    }, 350);
});
watch(
    () => props.rooms,
    (next) => (roomList.value = [...next]),
);
watch(inVideo, (active) => {
    if (active && localPreview.value && localStream)
        localPreview.value.srcObject = localStream;
});
onMounted(() => {
    void scrollBottom();
    void markRead();
    void pollCall();
    messagesTimer = window.setInterval(() => void pollMessages(), 3000);
    roomsTimer = window.setInterval(
        () => router.reload({ only: ['rooms'] }),
        10000,
    );
    callTimer = window.setInterval(() => void pollCall(), 2000);
    signalsTimer = window.setInterval(() => void pollSignals(), 1000);
    document.addEventListener('visibilitychange', markRead);
});
onBeforeUnmount(() => {
    if (messagesTimer) clearInterval(messagesTimer);
    if (roomsTimer) clearInterval(roomsTimer);
    if (callTimer) clearInterval(callTimer);
    if (signalsTimer) clearInterval(signalsTimer);
    if (searchTimer) clearTimeout(searchTimer);
    document.removeEventListener('visibilitychange', markRead);
    if (call.value && peer) void endCall();
    else closeMedia();
});
</script>

<template>
    <Head :title="t('Company chat')" />
    <div class="flex w-full flex-1 flex-col gap-3 p-4 md:p-6">
        <p v-if="error" class="text-destructive text-sm" role="alert">
            {{ error }}
        </p>
        <div
            class="bg-card flex h-[calc(100vh-8rem)] min-h-[32rem] overflow-hidden rounded-xl border shadow-xs"
        >
            <aside
                class="flex w-full flex-col border-e sm:w-80 lg:w-96"
                :class="mobileList ? 'flex' : 'hidden sm:flex'"
                :aria-label="t('Conversations')"
            >
                <div
                    class="flex items-center justify-between border-b px-3 py-2"
                >
                    <h1 class="font-display text-xl font-medium">
                        {{ t('Chats') }}
                    </h1>
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        :aria-label="t('Start a chat')"
                        @click="newChatOpen = true"
                        ><SquarePen class="size-4" aria-hidden="true"
                    /></Button>
                </div>
                <ChatRoomList
                    class="min-h-0 flex-1"
                    :rooms="roomList"
                    :active-room-id="activeRoomId"
                    :members="members"
                    :current-user-id="currentUserId"
                    @start-direct="startDirect"
                />
                <div
                    v-if="canExportChats"
                    class="space-y-1 border-t p-3 text-xs"
                >
                    <label class="block"
                        >{{ t('Export conversation') }}
                        <select
                            v-model="exportRoomId"
                            class="border-input bg-background mt-1 h-8 w-full rounded-md border px-2"
                        >
                            <option :value="null">
                                {{ t('All organization chats') }}
                            </option>
                            <option
                                v-for="item in exportRooms"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                    </label>
                    <a
                        :href="
                            exportRoomId
                                ? `/chat/export?room_id=${exportRoomId}`
                                : '/chat/export'
                        "
                        class="underline"
                        >{{ t('Download chat CSV') }}</a
                    >
                </div>
            </aside>

            <section
                class="min-w-0 flex-1 flex-col"
                :class="mobileList ? 'hidden sm:flex' : 'flex'"
                :aria-label="room?.name ?? t('Chat')"
            >
                <header class="flex items-center gap-3 border-b px-4 py-2">
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        class="sm:hidden"
                        :aria-label="t('Back to chats')"
                        @click="mobileList = true"
                        ><ArrowLeft class="size-4" aria-hidden="true"
                    /></Button>
                    <span
                        class="bg-primary/15 text-primary flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                        >{{ initials(room?.name ?? '?') }}</span
                    >
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate font-medium">
                            {{ room?.name ?? t('Chat') }}
                        </h2>
                        <p class="text-muted-foreground truncate text-xs">
                            {{ subtitle
                            }}<template v-if="call">
                                ·
                                {{
                                    call.status === 'ringing'
                                        ? t('Ringing…')
                                        : t('Call active')
                                }}</template
                            >
                        </p>
                    </div>
                    <template v-if="room?.kind === 'direct'">
                        <Button
                            v-if="!call"
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="startCall('audio')"
                            ><Phone class="size-4" aria-hidden="true" />{{
                                t('Voice call')
                            }}</Button
                        >
                        <Button
                            v-if="!call"
                            type="button"
                            size="sm"
                            @click="startCall('video')"
                            ><Video class="size-4" aria-hidden="true" />{{
                                t('Video call')
                            }}</Button
                        >
                        <Button
                            v-if="
                                call &&
                                call.recipient_id === currentUserId &&
                                !peer &&
                                call.status === 'ringing'
                            "
                            type="button"
                            size="sm"
                            @click="answerCall"
                            >{{
                                call.kind === 'video'
                                    ? t('Answer video call')
                                    : t('Answer call')
                            }}</Button
                        >
                        <Button
                            v-if="call"
                            type="button"
                            size="sm"
                            variant="destructive"
                            @click="endCall"
                            >{{ t('End call') }}</Button
                        >
                    </template>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :aria-label="t('Search messages')"
                        :aria-pressed="searchOpen"
                        @click="toggleSearch"
                        ><Search class="size-4" aria-hidden="true"
                    /></Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :aria-label="t('Members')"
                        :aria-pressed="membersOpen"
                        @click="membersOpen = !membersOpen"
                        ><Users class="size-4" aria-hidden="true"
                    /></Button>
                </header>
                <div
                    v-if="searchOpen"
                    class="flex items-center gap-2 border-b px-4 py-2"
                >
                    <Search
                        class="text-muted-foreground size-4"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="searchQuery"
                        type="search"
                        class="h-8"
                        :aria-label="t('Search messages')"
                        :placeholder="t('Search this chat')"
                    />
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :aria-label="t('Close search')"
                        @click="toggleSearch"
                        ><X class="size-4" aria-hidden="true"
                    /></Button>
                </div>
                <p
                    v-if="searchResults"
                    class="text-muted-foreground border-b px-4 py-1 text-xs"
                    role="status"
                >
                    {{ searchResults.length }}
                    {{ t('matches in the latest 2,000 messages') }}
                </p>
                <p
                    v-if="room?.kind === 'direct' && !iceServers.length && call"
                    class="text-muted-foreground border-b px-4 py-1 text-xs"
                >
                    {{
                        t(
                            'Calls across different networks may require a configured TURN server.',
                        )
                    }}
                </p>

                <div
                    v-show="inVideo"
                    class="bg-foreground/90 relative aspect-video max-h-72 w-full"
                >
                    <video
                        ref="remoteMedia"
                        class="size-full object-contain"
                        autoplay
                        playsinline
                    />
                    <video
                        ref="localPreview"
                        class="absolute end-3 bottom-3 h-24 rounded-md border-2 border-white/60 object-cover"
                        autoplay
                        muted
                        playsinline
                    />
                </div>

                <div class="flex min-h-0 flex-1">
                    <div class="flex min-w-0 flex-1 flex-col">
                        <ChatMessageList
                            ref="list"
                            :messages="shown"
                            :current-user-id="currentUserId"
                            :show-sender="room?.kind !== 'direct'"
                            :viewed-by="searchResults ? null : viewedBy"
                            :highlight="searchResults ? searchQuery : ''"
                            :can-load-older="
                                !searchResults && messages.length >= 100
                            "
                            @older="older"
                        />
                        <div class="border-t p-3">
                            <ChatComposer
                                v-model:body="body"
                                :mentionable="mentionable"
                                :busy="sending"
                                @send="send"
                                @attach="attach"
                                @voice="sendVoice"
                                @error="error = $event"
                            />
                        </div>
                    </div>
                    <aside
                        v-if="membersOpen"
                        class="hidden w-60 shrink-0 overflow-y-auto border-s p-3 md:block"
                        :aria-label="t('Members')"
                    >
                        <h3 class="text-eyebrow mb-2">
                            {{ t('Members') }} ({{ participants.length }})
                        </h3>
                        <ul class="space-y-1">
                            <li
                                v-for="member in participants"
                                :key="member.id"
                                class="flex items-center gap-2 py-1 text-sm"
                            >
                                <span
                                    class="bg-primary/15 text-primary flex size-7 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold"
                                    >{{ initials(member.name) }}</span
                                ><span class="truncate"
                                    >{{ member.name
                                    }}<span
                                        v-if="member.id === currentUserId"
                                        class="text-muted-foreground"
                                    >
                                        ({{ t('You') }})</span
                                    ></span
                                >
                            </li>
                        </ul>
                    </aside>
                </div>
            </section>
        </div>
        <ChatNewChatSheet
            v-model:open="newChatOpen"
            :members="members"
            :current-user-id="currentUserId"
            @direct="startDirect"
            @group="createGroup"
        />
    </div>
</template>
