<script setup lang="ts">
import { Mic, Paperclip, Send, Smile, Square, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useLocale } from '@/composables/useLocale';
import {
    EMOJI,
    activeMention,
    filterMembers,
    formatDuration,
    insertMention,
    mentionIds,
} from '@/lib/chat-view';
import type { ChatMember } from '@/lib/chat-view';

const MAX_VOICE_SECONDS = 300;
const props = defineProps<{ mentionable: ChatMember[]; busy: boolean }>();
const emit = defineEmits<{
    send: [body: string, mentions: number[]];
    attach: [files: File[]];
    voice: [file: File, seconds: number];
    error: [message: string];
}>();
const { t } = useLocale();
const body = defineModel<string>('body', { required: true });
const field = ref<HTMLTextAreaElement | null>(null);
const picker = ref<{ start: number; query: string } | null>(null);
const highlighted = ref(0);
const emojiOpen = ref(false);
const dragging = ref(false);
const recording = ref(false);
const seconds = ref(0);
let recorder: MediaRecorder | null = null;
let stream: MediaStream | null = null;
let chunks: Blob[] = [];
let timer: number | null = null;
let discard = false;

const suggestions = computed(() =>
    picker.value ? filterMembers(props.mentionable, picker.value.query) : [],
);
const canRecord =
    typeof window !== 'undefined' &&
    'MediaRecorder' in window &&
    !!navigator.mediaDevices?.getUserMedia;

function resize(): void {
    const el = field.value;
    if (el) {
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
    }
}
function detect(): void {
    resize();
    picker.value = field.value
        ? activeMention(body.value, field.value.selectionStart ?? 0)
        : null;
    highlighted.value = 0;
}
async function choose(member: ChatMember): Promise<void> {
    if (!picker.value || !field.value) {
        return;
    }
    const next = insertMention(
        body.value,
        field.value.selectionStart ?? 0,
        picker.value.start,
        member.name,
    );
    body.value = next.text;
    picker.value = null;
    await nextTick();
    field.value.focus();
    field.value.setSelectionRange(next.caret, next.caret);
    resize();
}
function keydown(event: KeyboardEvent): void {
    if (picker.value && suggestions.value.length) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            highlighted.value =
                (highlighted.value + step + suggestions.value.length) %
                suggestions.value.length;

            return;
        }
        if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            void choose(suggestions.value[highlighted.value]);

            return;
        }
        if (event.key === 'Escape') {
            picker.value = null;

            return;
        }
    }
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        submit();
    }
}
function submit(): void {
    const text = body.value.trim();
    if (!text || props.busy) {
        return;
    }
    emit('send', text, mentionIds(text, props.mentionable));
    picker.value = null;
    void nextTick(resize);
}
async function addEmoji(emoji: string): Promise<void> {
    const el = field.value;
    const at = el?.selectionStart ?? body.value.length;
    body.value =
        body.value.slice(0, at) +
        emoji +
        body.value.slice(el?.selectionEnd ?? at);
    emojiOpen.value = false;
    await nextTick();
    el?.focus();
    el?.setSelectionRange(at + emoji.length, at + emoji.length);
}
function files(list: FileList | File[] | null | undefined): void {
    const picked = Array.from(list ?? []);
    if (picked.length) {
        emit('attach', picked);
    }
}
function pasted(event: ClipboardEvent): void {
    const list = Array.from(event.clipboardData?.files ?? []);
    if (list.length) {
        event.preventDefault();
        files(list);
    }
}
function dropped(event: DragEvent): void {
    dragging.value = false;
    files(event.dataTransfer?.files);
}
function stopTimer(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}
function release(): void {
    stopTimer();
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
    recording.value = false;
}
async function startRecording(): Promise<void> {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const type = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/mp4',
            'audio/ogg',
        ].find((candidate) => MediaRecorder.isTypeSupported(candidate));
        recorder = new MediaRecorder(
            stream,
            type ? { mimeType: type } : undefined,
        );
        chunks = [];
        discard = false;
        recorder.ondataavailable = (event) =>
            event.data.size && chunks.push(event.data);
        recorder.onstop = () => {
            const length = seconds.value;
            const mime = recorder?.mimeType || 'audio/webm';
            release();
            if (!discard && chunks.length && length >= 1) {
                const extension = mime.includes('mp4')
                    ? 'm4a'
                    : mime.includes('ogg')
                      ? 'ogg'
                      : 'webm';
                emit(
                    'voice',
                    new File(chunks, `voice-message.${extension}`, {
                        type: mime,
                    }),
                    Math.min(length, MAX_VOICE_SECONDS),
                );
            }
        };
        seconds.value = 0;
        recorder.start();
        recording.value = true;
        timer = window.setInterval(() => {
            seconds.value++;
            if (seconds.value >= MAX_VOICE_SECONDS) {
                finishRecording(true);
            }
        }, 1000);
    } catch {
        release();
        emit(
            'error',
            t('The microphone is not available. Check the browser permission.'),
        );
    }
}
function finishRecording(send: boolean): void {
    discard = !send;
    stopTimer();
    if (recorder && recorder.state !== 'inactive') {
        recorder.stop();
    } else {
        release();
    }
}
onBeforeUnmount(() => {
    discard = true;
    if (recorder && recorder.state !== 'inactive') {
        recorder.stop();
    }
    release();
});
</script>

<template>
    <div
        class="bg-card relative rounded-2xl border p-2 shadow-xs"
        :class="{ 'ring-primary ring-2': dragging }"
        @dragover.prevent="dragging = true"
        @dragleave="dragging = false"
        @drop.prevent="dropped"
    >
        <ul
            v-if="picker && suggestions.length"
            class="bg-popover absolute inset-x-2 bottom-full z-20 mb-1 max-h-56 overflow-y-auto rounded-md border shadow-md"
            role="listbox"
            :aria-label="t('Mention someone')"
        >
            <li
                v-for="(member, index) in suggestions"
                :key="member.id"
                role="option"
                :aria-selected="index === highlighted"
            >
                <button
                    type="button"
                    class="hover:bg-muted flex w-full items-center gap-2 px-3 py-2 text-start text-sm"
                    :class="index === highlighted ? 'bg-muted' : ''"
                    @mousedown.prevent="choose(member)"
                >
                    {{ member.name }}
                </button>
            </li>
        </ul>
        <div v-if="recording" class="flex items-center gap-3 p-2" role="status">
            <span
                class="bg-destructive size-2.5 animate-pulse rounded-full"
                aria-hidden="true"
            />
            <span class="text-sm tabular-nums"
                >{{ t('Recording') }} {{ formatDuration(seconds) }}</span
            >
            <Button
                type="button"
                size="sm"
                variant="ghost"
                class="ms-auto"
                @click="finishRecording(false)"
                ><X class="size-4" aria-hidden="true" />{{
                    t('Cancel')
                }}</Button
            >
            <Button
                type="button"
                size="sm"
                :disabled="seconds < 1"
                @click="finishRecording(true)"
                ><Square class="size-4" aria-hidden="true" />{{
                    t('Send voice message')
                }}</Button
            >
        </div>
        <template v-else>
            <textarea
                ref="field"
                v-model="body"
                rows="1"
                maxlength="5000"
                class="placeholder:text-muted-foreground block max-h-40 w-full resize-none bg-transparent px-2 py-1.5 text-sm outline-none"
                :aria-label="t('Message')"
                :placeholder="t('Type @ to mention a person…')"
                @input="detect"
                @click="detect"
                @keyup.left="detect"
                @keyup.right="detect"
                @keydown="keydown"
                @paste="pasted"
            />
            <div class="flex items-center gap-1">
                <label
                    class="hover:bg-muted text-muted-foreground cursor-pointer rounded-md p-2"
                    :title="t('Attach files')"
                    ><Paperclip class="size-5" aria-hidden="true" /><span
                        class="sr-only"
                        >{{ t('Attach files') }}</span
                    ><input
                        type="file"
                        multiple
                        class="sr-only"
                        @change="
                            files(($event.target as HTMLInputElement).files);
                            ($event.target as HTMLInputElement).value = '';
                        "
                /></label>
                <Popover v-model:open="emojiOpen">
                    <PopoverTrigger as-child
                        ><button
                            type="button"
                            class="hover:bg-muted text-muted-foreground rounded-md p-2"
                            :aria-label="t('Emoji')"
                        >
                            <Smile class="size-5" aria-hidden="true" /></button
                    ></PopoverTrigger>
                    <PopoverContent align="start" class="w-64 p-2"
                        ><div class="grid grid-cols-8 gap-1">
                            <button
                                v-for="emoji in EMOJI"
                                :key="emoji"
                                type="button"
                                class="hover:bg-muted rounded p-1 text-lg"
                                :aria-label="emoji"
                                @click="addEmoji(emoji)"
                            >
                                {{ emoji }}
                            </button>
                        </div></PopoverContent
                    >
                </Popover>
                <button
                    v-if="canRecord"
                    type="button"
                    class="hover:bg-muted text-muted-foreground rounded-md p-2"
                    :aria-label="t('Record a voice message')"
                    @click="startRecording"
                >
                    <Mic class="size-5" aria-hidden="true" />
                </button>
                <Button
                    type="button"
                    size="icon"
                    class="ms-auto rounded-full"
                    :disabled="busy || !body.trim()"
                    :aria-label="t('Send')"
                    @click="submit"
                    ><Send class="size-4" aria-hidden="true"
                /></Button>
            </div>
        </template>
    </div>
</template>
