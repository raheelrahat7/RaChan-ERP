export type ChatMember = { id: number; name: string };
export type ChatAttachment = {
    id: number;
    name: string;
    mime: string;
    size: number;
    kind: 'file' | 'image' | 'voice';
    duration_seconds: number | null;
    url: string;
};
export type ChatMessage = {
    id: number;
    room_id: number;
    user_id: number | null;
    sender: string;
    body: string;
    created_at: string;
    mentions?: ChatMember[];
    attachments?: ChatAttachment[];
};

export type MessageRun = {
    key: string;
    userId: number | null;
    sender: string;
    messages: ChatMessage[];
};
export type DayGroup = { day: string; label: string; runs: MessageRun[] };

export const EMOJI = [
    '👍',
    '👎',
    '😀',
    '😂',
    '😊',
    '😍',
    '🙏',
    '👏',
    '🎉',
    '🔥',
    '✅',
    '❌',
    '❤️',
    '😮',
    '😢',
    '🤔',
    '👀',
    '💯',
    '🏠',
    '📞',
    '📎',
    '⏰',
    '✔️',
    '🚀',
];

export function initials(name: string): string {
    return (
        name
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map((part) => part[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

function dayKey(date: Date): string {
    const pad = (n: number): string => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function dayLabel(date: Date, now: Date, locale = 'en'): string {
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const that = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const diff = Math.round((today.getTime() - that.getTime()) / 86_400_000);
    if (diff === 0) {
        return 'Today';
    }
    if (diff === 1) {
        return 'Yesterday';
    }

    return new Intl.DateTimeFormat(locale, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        ...(that.getFullYear() === today.getFullYear()
            ? {}
            : { year: 'numeric' }),
    }).format(date);
}

/** Groups messages into days, then into consecutive runs by the same sender within 5 minutes. */
export function groupMessages(
    messages: ChatMessage[],
    now = new Date(),
    locale = 'en',
): DayGroup[] {
    const days: DayGroup[] = [];
    for (const message of messages) {
        const date = new Date(message.created_at);
        const day = dayKey(date);
        let group = days.at(-1);
        if (!group || group.day !== day) {
            group = { day, label: dayLabel(date, now, locale), runs: [] };
            days.push(group);
        }
        const run = group.runs.at(-1);
        const previous = run?.messages.at(-1);
        const sameRun =
            run &&
            previous &&
            run.userId === message.user_id &&
            date.getTime() - new Date(previous.created_at).getTime() <
                5 * 60_000;
        if (sameRun) {
            run.messages.push(message);
        } else {
            group.runs.push({
                key: `run-${message.id}`,
                userId: message.user_id,
                sender: message.sender,
                messages: [message],
            });
        }
    }

    return days;
}

/** Member ids whose "@Full Name" appears in the text (longest names first, no partial words). */
export function mentionIds(text: string, members: ChatMember[]): number[] {
    const found: number[] = [];
    let remaining = text;
    for (const member of [...members].sort(
        (a, b) => b.name.length - a.name.length,
    )) {
        const pattern = new RegExp(
            `(^|[^\\p{L}\\p{N}])@${escapeRegExp(member.name)}(?![\\p{L}\\p{N}])`,
            'u',
        );
        if (pattern.test(remaining)) {
            found.push(member.id);
            remaining = remaining.replace(pattern, '$1');
        }
    }

    return found;
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** If the caret sits inside an "@query" token, returns its start and the query. */
export function activeMention(
    text: string,
    caret: number,
): { start: number; query: string } | null {
    const before = text.slice(0, caret);
    const match = /(^|\s)@([^\s@]{0,30})$/u.exec(before);

    return match
        ? { start: before.length - match[2].length - 1, query: match[2] }
        : null;
}

export function insertMention(
    text: string,
    caret: number,
    start: number,
    name: string,
): { text: string; caret: number } {
    const inserted = `@${name} `;
    const next = text.slice(0, start) + inserted + text.slice(caret);

    return { text: next, caret: start + inserted.length };
}

export function filterMembers(
    members: ChatMember[],
    query: string,
    limit = 6,
): ChatMember[] {
    const needle = query.trim().toLowerCase();

    return members
        .filter(
            (member) => !needle || member.name.toLowerCase().includes(needle),
        )
        .slice(0, limit);
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export function formatDuration(seconds: number | null): string {
    const total = Math.max(0, Math.round(seconds ?? 0));

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

export function roomSubtitle(kind: string, memberCount: number): string {
    if (kind === 'workspace') {
        return `Company · ${memberCount} members`;
    }
    if (kind === 'group') {
        return `Group · ${memberCount} members`;
    }

    return 'Direct message';
}

/** "Viewed by A, B and 6 more" - empty string when nobody has read it. */
export function viewedText(names: string[], shown = 2): string {
    if (!names.length) {
        return '';
    }
    const head = names.slice(0, shown).join(', ');
    const rest = names.length - shown;

    return rest > 0 ? `${head} and ${rest} more` : head;
}

export function attachmentLabel(kind: string | null | undefined): string {
    return kind === 'voice'
        ? 'Voice message'
        : kind === 'image'
          ? 'Photo'
          : kind
            ? 'Attachment'
            : '';
}

export function unreadLabel(count: number): string {
    return count > 99 ? '99+' : String(count);
}
