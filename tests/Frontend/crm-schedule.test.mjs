import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    completeMeetingBody,
    endsBeforeStart,
    fromLocalInput,
    hasChanges,
    meetingCreateBody,
    meetingForm,
    meetingUpdateBody,
    taskCreateBody,
    taskForm,
    taskUpdateBody,
    toLocalInput,
    whenText,
} from '../../resources/js/lib/crm-schedule.ts';

const task = {
    id: 7,
    title: 'Call buyer',
    description: null,
    priority: 'normal',
    status: 'open',
    assigned_to: 12,
    assignee_name: 'A',
    due_at: '2027-01-02 12:00:00',
    completed_at: null,
    version: 3,
    permissions: {},
};
const meeting = {
    id: 9,
    type: 'viewing',
    title: 'Villa viewing',
    assigned_to: 12,
    assignee_name: 'A',
    listing_id: null,
    starts_at: '2027-01-02 10:00:00',
    ends_at: '2027-01-02 11:00:00',
    location: 'Lobby',
    status: 'scheduled',
    outcome: null,
    version: 2,
    permissions: {},
};

await test('server UTC strings become local inputs and back', () => {
    const local = toLocalInput('2027-01-02 12:00:00');
    assert.match(local, /^2027-01-0[23]T\d\d:\d\d$/);
    assert.equal(fromLocalInput(local), '2027-01-02T12:00:00.000Z');
    assert.equal(toLocalInput('2027-01-02T12:00:00+00:00'), local);
    assert.equal(toLocalInput(null), '');
    assert.equal(fromLocalInput(' '), null);
    assert.equal(whenText(null), '—');
});

await test('task create and update bodies', () => {
    const form = taskForm(undefined, 12);
    form.title = '  Call buyer ';
    assert.deepEqual(taskCreateBody(form), {
        title: 'Call buyer',
        description: null,
        priority: 'normal',
        assigned_to: 12,
        due_at: null,
    });
    const edit = taskForm(task);
    assert.deepEqual(taskUpdateBody(task, edit), { expected_version: 3 });
    edit.title = 'Call again';
    edit.priority = 'high';
    edit.assigned_to = '';
    assert.deepEqual(taskUpdateBody(task, edit), {
        expected_version: 3,
        title: 'Call again',
        priority: 'high',
        assigned_to: null,
    });
    assert.equal(hasChanges({ expected_version: 3 }), false);
});

await test('meeting create and update bodies', () => {
    const form = {
        ...meetingForm(undefined, 12),
        title: 'Viewing',
        starts_at: '2027-01-02T10:00',
        ends_at: '2027-01-02T11:00',
    };
    const body = meetingCreateBody(form);
    assert.equal(body.assigned_to, 12);
    assert.equal(body.listing_id, null);
    assert.equal(body.location, null);
    const edit = meetingForm(meeting);
    assert.deepEqual(meetingUpdateBody(meeting, edit), { expected_version: 2 });
    edit.location = 'Reception';
    edit.listing_id = '5';
    assert.deepEqual(meetingUpdateBody(meeting, edit), {
        expected_version: 2,
        location: 'Reception',
        listing_id: 5,
    });
});

await test('completing a meeting pairs the stage with the one it left', () => {
    assert.deepEqual(completeMeetingBody(meeting, ' Interested ', null, 4), {
        expected_version: 2,
        outcome: 'Interested',
    });
    assert.deepEqual(completeMeetingBody(meeting, '', 6, 4), {
        expected_version: 2,
        outcome: null,
        stage_id: 6,
        expected_stage_id: 4,
    });
    assert.equal('stage_id' in completeMeetingBody(meeting, '', 4, 4), false);
    assert.equal(endsBeforeStart('2027-01-02T10:00', '2027-01-02T09:00'), true);
    assert.equal(endsBeforeStart('2027-01-02T10:00', ''), false);
});
