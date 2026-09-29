<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Member = { id: number; name: string };
type Room = {
    id: number;
    kind: 'workspace' | 'direct' | 'group';
    name: string;
    members: Member[];
};
type Message = {
    id: number;
    room_id: number;
    user_id: number;
    sender: string;
    body: string;
    created_at: string;
};
type Call = {
    id: number;
    room_id: number;
    initiator_id: number;
    recipient_id: number;
    status: 'ringing' | 'active';
};
type Signal = {
    id: number;
    type: 'offer' | 'answer' | 'ice';
    payload: RTCSessionDescriptionInit | RTCIceCandidateInit;
};
const props = defineProps<{
    rooms: Room[];
    activeRoomId: number;
    messages: Message[];
    members: Member[];
    currentUserId: number;
    canExportChats: boolean;
    exportRooms: { id: number; name: string }[];
    iceServers: RTCIceServer[];
}>();
const room = computed(() =>
    props.rooms.find((item) => item.id === props.activeRoomId),
);
const messages = ref<Message[]>([...props.messages]);
const body = ref('');
const recipientId = ref<number | null>(null);
const groupName = ref('');
const exportRoomId = ref<number | null>(null);
const groupMembers = ref<number[]>([]);
const error = ref('');
const sending = ref(false);
const scrollbox = ref<HTMLElement | null>(null);
const remoteAudio = ref<HTMLAudioElement | null>(null);
const call = ref<Call | null>(null);
const calling = ref(false);
const answering = ref(false);
let peer: RTCPeerConnection | null = null;
let localStream: MediaStream | null = null;
let messagesTimer: number | null = null;
let callTimer: number | null = null;
let signalsTimer: number | null = null;
let lastSignalId = 0;
const pendingIce: RTCIceCandidateInit[] = [];

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
async function scrollBottom(): Promise<void> {
    await nextTick();
    scrollbox.value?.scrollTo({ top: scrollbox.value.scrollHeight });
}
async function pollMessages(): Promise<void> {
    try {
        const after = messages.value.at(-1)?.id ?? 0;
        const result = await json<{ messages: Message[] }>(
            `/chat/rooms/${props.activeRoomId}/messages?after=${after}`,
        );
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
        }
    } catch {
        /* Retain local messages during a transient connection loss. */
    }
}
async function older(): Promise<void> {
    const before = messages.value[0]?.id;
    if (!before) return;
    try {
        const result = await json<{ messages: Message[] }>(
            `/chat/rooms/${props.activeRoomId}/messages?before=${before}`,
        );
        messages.value.unshift(...result.messages);
    } catch (cause) {
        error.value = (cause as Error).message;
    }
}
async function send(): Promise<void> {
    if (!body.value.trim() || sending.value) return;
    sending.value = true;
    error.value = '';
    try {
        const result = await json<{ message: Message }>(
            `/chat/rooms/${props.activeRoomId}/messages`,
            'POST',
            { body: body.value },
        );
        body.value = '';
        messages.value.push(result.message);
        await scrollBottom();
    } catch (cause) {
        error.value = (cause as Error).message;
    } finally {
        sending.value = false;
    }
}
async function direct(): Promise<void> {
    if (!recipientId.value) return;
    try {
        const result = await json<{ room_id: number }>('/chat/direct', 'POST', {
            recipient_id: recipientId.value,
        });
        window.location.assign(`/chat?room=${result.room_id}`);
    } catch (cause) {
        error.value = (cause as Error).message;
    }
}
async function group(): Promise<void> {
    try {
        const result = await json<{ room_id: number }>('/chat/group', 'POST', {
            name: groupName.value,
            members: groupMembers.value,
        });
        window.location.assign(`/chat?room=${result.room_id}`);
    } catch (cause) {
        error.value = (cause as Error).message;
    }
}
function toggleMember(id: number): void {
    groupMembers.value = groupMembers.value.includes(id)
        ? groupMembers.value.filter((member) => member !== id)
        : [...groupMembers.value, id];
}
function closeMedia(): void {
    peer?.close();
    peer = null;
    localStream?.getTracks().forEach((track) => track.stop());
    localStream = null;
    if (remoteAudio.value) remoteAudio.value.srcObject = null;
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
async function setupPeer(): Promise<void> {
    localStream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: false,
    });
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
        if (remoteAudio.value) {
            remoteAudio.value.srcObject = event.streams[0];
            void remoteAudio.value.play().catch(() => {});
        }
    };
    peer.onconnectionstatechange = () => {
        if (peer?.connectionState === 'failed')
            error.value =
                'Audio connection failed. A TURN server may be required for these networks.';
    };
}
async function startCall(): Promise<void> {
    error.value = '';
    try {
        await setupPeer();
        const result = await json<{ call: Call }>(
            `/chat/rooms/${props.activeRoomId}/call`,
            'POST',
        );
        call.value = result.call;
        calling.value = true;
        lastSignalId = 0;
        const offer = await peer!.createOffer();
        await peer!.setLocalDescription(offer);
        await signal('offer', offer);
    } catch (cause) {
        closeMedia();
        error.value = (cause as Error).message;
    }
}
async function answerCall(): Promise<void> {
    error.value = '';
    try {
        await setupPeer();
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
onMounted(() => {
    void scrollBottom();
    void pollCall();
    messagesTimer = window.setInterval(() => {
        void pollMessages();
    }, 3000);
    callTimer = window.setInterval(() => {
        void pollCall();
    }, 2000);
    signalsTimer = window.setInterval(() => {
        void pollSignals();
    }, 1000);
});
onBeforeUnmount(() => {
    if (messagesTimer) clearInterval(messagesTimer);
    if (callTimer) clearInterval(callTimer);
    if (signalsTimer) clearInterval(signalsTimer);
    if (call.value && peer) void endCall();
    else closeMedia();
});
</script>

<template>
    <Head title="Company chat" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Company chat"
            description="One chat for everyone in your ERP organization, plus private direct messages and groups"
        />
        <p v-if="error" class="text-destructive text-sm" role="alert">
            {{ error }}
        </p>
        <div class="grid gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
            <div class="space-y-5">
                <Card
                    ><CardHeader
                        ><CardTitle>Conversations</CardTitle></CardHeader
                    ><CardContent class="space-y-2"
                        ><Link
                            v-for="item in rooms"
                            :key="item.id"
                            :href="`/chat?room=${item.id}`"
                            class="hover:bg-muted block rounded-md border p-3 text-sm"
                            :class="
                                item.id === activeRoomId
                                    ? 'bg-muted font-medium'
                                    : ''
                            "
                            >{{ item.name }}
                            <span class="text-muted-foreground text-xs"
                                >· {{ item.kind }}</span
                            ></Link
                        ></CardContent
                    ></Card
                >
                <Card
                    ><CardHeader><CardTitle>Start a chat</CardTitle></CardHeader
                    ><CardContent class="space-y-3"
                        ><label class="block text-sm"
                            >Person<select
                                v-model="recipientId"
                                class="border-input bg-background mt-1 h-9 w-full rounded-md border px-2"
                            >
                                <option :value="null">Choose a member</option>
                                <option
                                    v-for="member in members.filter(
                                        (item) => item.id !== currentUserId,
                                    )"
                                    :key="member.id"
                                    :value="member.id"
                                >
                                    {{ member.name }}
                                </option>
                            </select></label
                        ><Button
                            :disabled="!recipientId"
                            size="sm"
                            @click="direct"
                            >Open direct chat</Button
                        >
                        <div class="border-t pt-3">
                            <label class="text-sm"
                                >Group name<Input
                                    v-model="groupName"
                                    class="mt-1"
                                    maxlength="120"
                            /></label>
                            <p class="mt-2 text-xs">
                                Select at least two members
                            </p>
                            <label
                                v-for="member in members.filter(
                                    (item) => item.id !== currentUserId,
                                )"
                                :key="member.id"
                                class="flex items-center gap-2 py-1 text-sm"
                                ><input
                                    type="checkbox"
                                    :checked="groupMembers.includes(member.id)"
                                    @change="toggleMember(member.id)"
                                />{{ member.name }}</label
                            ><Button
                                size="sm"
                                :disabled="
                                    !groupName.trim() || groupMembers.length < 2
                                "
                                @click="group"
                                >Create group</Button
                            >
                        </div></CardContent
                    ></Card
                >
                <div v-if="canExportChats" class="space-y-2">
                    <label class="block text-sm"
                        >Export conversation<select
                            v-model="exportRoomId"
                            class="border-input bg-background mt-1 h-9 w-full rounded-md border px-2"
                        >
                            <option :value="null">
                                All organization chats
                            </option>
                            <option
                                v-for="item in exportRooms"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select></label
                    ><a
                        :href="
                            exportRoomId
                                ? `/chat/export?room_id=${exportRoomId}`
                                : '/chat/export'
                        "
                        class="text-sm underline"
                        >Download chat CSV</a
                    >
                </div>
            </div>
            <Card class="min-w-0"
                ><CardHeader
                    ><div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <CardTitle>{{ room?.name ?? 'Chat' }}</CardTitle>
                        <div class="flex gap-2">
                            <Button
                                v-if="room?.kind === 'direct' && !call"
                                size="sm"
                                variant="outline"
                                @click="startCall"
                                >Voice call</Button
                            ><Button
                                v-if="
                                    call &&
                                    call.recipient_id === currentUserId &&
                                    !peer &&
                                    call.status === 'ringing'
                                "
                                size="sm"
                                @click="answerCall"
                                >Answer call</Button
                            ><Button
                                v-if="call"
                                size="sm"
                                variant="destructive"
                                @click="endCall"
                                >End call</Button
                            >
                        </div>
                    </div>
                    <p v-if="call" class="text-muted-foreground text-xs">
                        {{
                            call.status === 'ringing'
                                ? 'Ringing…'
                                : 'Call active'
                        }}
                    </p>
                    <p
                        v-if="room?.kind === 'direct' && !iceServers.length"
                        class="text-muted-foreground text-xs"
                    >
                        Calls across different networks may require a configured
                        TURN server.
                    </p></CardHeader
                ><CardContent class="space-y-3"
                    ><Button
                        v-if="messages.length >= 100"
                        size="sm"
                        variant="ghost"
                        @click="older"
                        >Load older messages</Button
                    >
                    <div
                        ref="scrollbox"
                        class="h-[min(60vh,650px)] space-y-3 overflow-y-auto rounded-md border p-3"
                        role="log"
                        aria-label="Chat messages"
                    >
                        <div
                            v-for="message in messages"
                            :key="message.id"
                            class="max-w-[85%] rounded-md border p-3 text-sm"
                            :class="
                                message.user_id === currentUserId
                                    ? 'bg-primary/10 ml-auto'
                                    : 'bg-muted'
                            "
                        >
                            <div
                                class="mb-1 flex justify-between gap-4 text-xs"
                            >
                                <strong>{{ message.sender }}</strong
                                ><time class="text-muted-foreground">{{
                                    new Date(
                                        message.created_at,
                                    ).toLocaleString()
                                }}</time>
                            </div>
                            <p class="break-words whitespace-pre-wrap">
                                {{ message.body }}
                            </p>
                        </div>
                        <p
                            v-if="!messages.length"
                            class="text-muted-foreground text-sm"
                        >
                            No messages yet.
                        </p>
                    </div>
                    <form class="flex gap-2" @submit.prevent="send">
                        <Input
                            v-model="body"
                            aria-label="Message"
                            maxlength="5000"
                            placeholder="Write a message"
                        /><Button :disabled="sending || !body.trim()"
                            >Send</Button
                        >
                    </form>
                    <audio ref="remoteAudio" autoplay /></CardContent
            ></Card>
        </div>
    </div>
</template>
