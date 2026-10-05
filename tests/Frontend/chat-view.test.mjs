import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    activeMention,
    attachmentLabel,
    dayLabel,
    filterMembers,
    formatBytes,
    formatDuration,
    groupMessages,
    initials,
    insertMention,
    mentionIds,
    roomSubtitle,
    unreadLabel,
    viewedText,
} from '../../resources/js/lib/chat-view.ts';

const now = new Date(2026, 9, 14, 15, 0);
const msg = (id, user, iso, extra = {}) => ({
    id,
    room_id: 1,
    user_id: user,
    sender: `U${user}`,
    body: 'x',
    created_at: iso,
    ...extra,
});
const at = (d, h, m) => new Date(2026, 9, d, h, m).toISOString();

await test('days get friendly labels and runs split by sender, gap and day', () => {
    assert.equal(dayLabel(new Date(2026, 9, 14, 1), now), 'Today');
    assert.equal(dayLabel(new Date(2026, 9, 13, 23), now), 'Yesterday');
    assert.match(dayLabel(new Date(2026, 9, 1), now), /October/);
    assert.match(dayLabel(new Date(2025, 11, 1), now), /2025/);
    const groups = groupMessages(
        [
            msg(1, 1, at(13, 10, 0)),
            msg(2, 1, at(13, 10, 2)),
            msg(3, 1, at(13, 10, 30)),
            msg(4, 2, at(13, 10, 31)),
            msg(5, 2, at(14, 9, 0)),
        ],
        now,
    );
    assert.deepEqual(
        groups.map((g) => g.label),
        ['Yesterday', 'Today'],
    );
    assert.deepEqual(
        groups[0].runs.map((r) => r.messages.map((m) => m.id)),
        [[1, 2], [3], [4]],
    );
    assert.deepEqual(
        groups[1].runs.map((r) => r.messages.map((m) => m.id)),
        [[5]],
    );
    assert.deepEqual(groupMessages([], now), []);
});

await test('mentions are extracted by full name without matching partial words', () => {
    const members = [
        { id: 1, name: 'Ali' },
        { id: 2, name: 'Ali Rizvi' },
        { id: 3, name: 'Sana' },
    ];
    assert.deepEqual(
        mentionIds('Hi @Ali Rizvi and @Sana!', members).sort((a, b) => a - b),
        [2, 3],
    );
    assert.deepEqual(mentionIds('@Ali first', members), [1]);
    assert.deepEqual(mentionIds('mail@Sana.com', members), []);
    assert.deepEqual(mentionIds('@Alice', members), []);
    assert.deepEqual(mentionIds('no one', members), []);
    assert.deepEqual(mentionIds('Dot @A.B', [{ id: 9, name: 'A.B' }]), [9]);
});

await test('the mention picker finds the token at the caret and inserts a name', () => {
    assert.deepEqual(activeMention('hello @sa', 9), { start: 6, query: 'sa' });
    assert.deepEqual(activeMention('@', 1), { start: 0, query: '' });
    assert.equal(activeMention('hello world', 5), null);
    assert.equal(activeMention('a@b', 3), null);
    const result = insertMention('hello @sa there', 9, 6, 'Sana Nadeem');
    assert.equal(result.text, 'hello @Sana Nadeem  there');
    assert.equal(result.caret, 19);
    const members = [
        { id: 1, name: 'Sana Nadeem' },
        { id: 2, name: 'Hamza Arif' },
    ];
    assert.deepEqual(
        filterMembers(members, 'ham').map((m) => m.id),
        [2],
    );
    assert.equal(filterMembers(members, '').length, 2);
});

await test('formatting helpers stay readable', () => {
    assert.equal(initials('Raheel Rahat'), 'RR');
    assert.equal(initials('  '), '?');
    assert.equal(formatBytes(500), '500 B');
    assert.equal(formatBytes(2048), '2 KB');
    assert.equal(formatBytes(3 * 1024 * 1024), '3.0 MB');
    assert.equal(formatDuration(75), '1:15');
    assert.equal(formatDuration(null), '0:00');
    assert.equal(roomSubtitle('workspace', 33), 'Company · 33 members');
    assert.equal(roomSubtitle('direct', 2), 'Direct message');
    assert.equal(viewedText([]), '');
    assert.equal(viewedText(['A']), 'A');
    assert.equal(viewedText(['A', 'B', 'C', 'D']), 'A, B and 2 more');
    assert.equal(attachmentLabel('voice'), 'Voice message');
    assert.equal(attachmentLabel(null), '');
    assert.equal(unreadLabel(120), '99+');
    assert.equal(unreadLabel(3), '3');
});
