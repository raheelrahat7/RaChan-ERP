<script setup lang="ts">
import { CheckCheck, Download, FileText } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import {
    formatBytes,
    formatDuration,
    groupMessages,
    initials,
    viewedText,
} from '@/lib/chat-view';
import type { ChatMessage } from '@/lib/chat-view';

const props = defineProps<{
    messages: ChatMessage[];
    currentUserId: number;
    showSender: boolean;
    viewedBy: {
        message_id: number;
        users: { id: number; name: string }[];
    } | null;
    highlight?: string;
    canLoadOlder?: boolean;
}>();
const emit = defineEmits<{ older: [] }>();
const { t, locale } = useLocale();
const box = ref<HTMLElement | null>(null);
const groups = computed(() =>
    groupMessages(props.messages, new Date(), locale.value),
);
const viewed = computed(() =>
    props.viewedBy
        ? viewedText(props.viewedBy.users.map((user) => user.name))
        : '',
);

function time(value: string): string {
    return new Date(value).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}
function escape(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
/** Splits a body into plain, @mention and search-match pieces; rendered as text, never as HTML. */
function pieces(
    message: ChatMessage,
): { text: string; kind: 'plain' | 'mention' | 'match' }[] {
    const names = (message.mentions ?? []).map((mention) => `@${mention.name}`);
    const needle = props.highlight?.trim();
    const parts = [...names.map(escape), ...(needle ? [escape(needle)] : [])];
    if (!parts.length) {
        return [{ text: message.body, kind: 'plain' }];
    }

    return message.body
        .split(new RegExp(`(${parts.join('|')})`, 'gi'))
        .filter((text) => text !== '')
        .map((text) => ({
            text,
            kind: names.some(
                (name) => name.toLowerCase() === text.toLowerCase(),
            )
                ? 'mention'
                : needle && text.toLowerCase() === needle.toLowerCase()
                  ? 'match'
                  : 'plain',
        }));
}
async function scrollToBottom(): Promise<void> {
    await nextTick();
    box.value?.scrollTo({ top: box.value.scrollHeight });
}
defineExpose({ scrollToBottom });
</script>

<template>
    <div
        ref="box"
        class="bg-muted/30 min-h-0 flex-1 space-y-2 overflow-y-auto px-4 py-3"
        role="log"
        :aria-label="t('Chat messages')"
    >
        <div v-if="canLoadOlder" class="text-center">
            <Button
                type="button"
                size="sm"
                variant="ghost"
                @click="emit('older')"
                >{{ t('Load older messages') }}</Button
            >
        </div>
        <p
            v-if="!messages.length"
            class="text-muted-foreground py-10 text-center text-sm"
        >
            {{ t('No messages yet.') }}
        </p>
        <section v-for="day in groups" :key="day.day" class="space-y-2">
            <h3 class="sticky top-0 z-10 flex justify-center">
                <span
                    class="bg-background/90 text-muted-foreground rounded-full border px-3 py-0.5 text-xs shadow-xs"
                    >{{ t(day.label) }}</span
                >
            </h3>
            <div
                v-for="run in day.runs"
                :key="run.key"
                class="flex gap-2"
                :class="run.userId === currentUserId ? 'flex-row-reverse' : ''"
            >
                <span
                    v-if="run.userId !== currentUserId && showSender"
                    class="bg-primary/15 text-primary mt-5 flex size-8 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                    aria-hidden="true"
                    >{{ initials(run.sender) }}</span
                >
                <div
                    class="flex max-w-[78%] flex-col gap-1"
                    :class="
                        run.userId === currentUserId
                            ? 'items-end'
                            : 'items-start'
                    "
                >
                    <p
                        v-if="run.userId !== currentUserId && showSender"
                        class="text-muted-foreground px-1 text-xs font-medium"
                    >
                        {{ run.sender }}
                    </p>
                    <div
                        v-for="message in run.messages"
                        :key="message.id"
                        class="rounded-2xl px-3 py-2 text-sm shadow-xs"
                        :class="
                            run.userId === currentUserId
                                ? 'bg-primary/15 rounded-ee-sm'
                                : 'bg-card rounded-es-sm border'
                        "
                    >
                        <p
                            v-if="message.body"
                            class="break-words whitespace-pre-wrap"
                        >
                            <template
                                v-for="(piece, index) in pieces(message)"
                                :key="index"
                                ><mark
                                    v-if="piece.kind === 'match'"
                                    class="bg-yellow-200 text-inherit"
                                    >{{ piece.text }}</mark
                                ><span
                                    v-else-if="piece.kind === 'mention'"
                                    class="text-primary font-medium"
                                    >{{ piece.text }}</span
                                ><template v-else>{{
                                    piece.text
                                }}</template></template
                            >
                        </p>
                        <div
                            v-for="file in message.attachments ?? []"
                            :key="file.id"
                            class="mt-1"
                        >
                            <a
                                v-if="file.kind === 'image'"
                                :href="file.url"
                                target="_blank"
                                rel="noopener"
                                ><img
                                    :src="file.url"
                                    :alt="file.name"
                                    loading="lazy"
                                    class="max-h-60 rounded-lg"
                            /></a>
                            <div
                                v-else-if="file.kind === 'voice'"
                                class="flex items-center gap-2"
                            >
                                <audio
                                    controls
                                    preload="none"
                                    :src="file.url"
                                    class="h-9 max-w-full"
                                /><span
                                    class="text-muted-foreground text-xs tabular-nums"
                                    >{{
                                        formatDuration(file.duration_seconds)
                                    }}</span
                                >
                            </div>
                            <a
                                v-else
                                :href="file.url"
                                class="hover:bg-muted flex items-center gap-2 rounded-lg border px-3 py-2"
                                download
                                ><FileText
                                    class="text-primary size-5 shrink-0"
                                    aria-hidden="true" /><span class="min-w-0"
                                    ><span
                                        class="block truncate text-sm font-medium"
                                        >{{ file.name }}</span
                                    ><span
                                        class="text-muted-foreground block text-xs"
                                        >{{ formatBytes(file.size) }}</span
                                    ></span
                                ><Download
                                    class="text-muted-foreground size-4 shrink-0"
                                    aria-hidden="true"
                            /></a>
                        </div>
                        <time
                            class="text-muted-foreground mt-0.5 block text-end text-[10px]"
                            :datetime="message.created_at"
                            >{{ time(message.created_at) }}</time
                        >
                    </div>
                    <p
                        v-if="
                            viewed &&
                            run.messages.some(
                                (m) => m.id === viewedBy?.message_id,
                            )
                        "
                        class="text-muted-foreground flex items-center gap-1 px-1 text-xs"
                    >
                        <CheckCheck
                            class="text-primary size-3.5"
                            aria-hidden="true"
                        />{{ t('Viewed by') }} {{ viewed }}
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
