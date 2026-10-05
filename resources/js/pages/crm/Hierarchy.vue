<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Named = { id: number; name: string; active: boolean };
type Subdepartment = Named & { department_id: number };
type Team = Named & { subdepartment_id: number };
type Member = { id: number; name: string; role: string };
type Grant = {
    id: number;
    user_id: number;
    scope_type: string;
    scope_id: number;
};
const props = defineProps<{
    departments: Named[];
    subdepartments: Subdepartment[];
    teams: Team[];
    members: Member[];
    placements: { user_id: number; team_id: number }[];
    grants: Grant[];
    editGrantUserIds: number[];
}>();
const page = usePage();
const department = reactive({ name: '' });
const subdepartment = reactive({
    name: '',
    department_id: null as number | null,
});
const team = reactive({ name: '', subdepartment_id: null as number | null });
const placement = reactive({
    user_id: null as number | null,
    team_id: null as number | null,
});
const grant = reactive({
    user_id: null as number | null,
    scope_type: 'department',
    scope_id: null as number | null,
});
const save = (kind: string, data: Record<string, string | number | null>) =>
    router.post(`/crm/hierarchy/${kind}`, data, { preserveScroll: true });
const savePlacement = () =>
    router.put('/crm/hierarchy/placement', placement, { preserveScroll: true });
const saveGrant = () =>
    router.post('/crm/hierarchy/grants', grant, { preserveScroll: true });
const revoke = (id: number) =>
    router.delete(`/crm/hierarchy/grants/${id}`, { preserveScroll: true });
const setEditGrant = (userId: number, allowed: boolean) =>
    router.put(
        '/crm/hierarchy/edit-grant',
        { user_id: userId, allowed },
        { preserveScroll: true },
    );
const setActive = (kind: string, item: Named) =>
    router.put(
        `/crm/hierarchy/${kind}/${item.id}/active`,
        { active: !item.active },
        { preserveScroll: true },
    );
const nameOf = (items: Named[], id: number) =>
    items.find((item) => item.id === id)?.name ?? `#${id}`;
const scopeName = (item: Grant) =>
    item.scope_type === 'organization'
        ? 'Whole organization'
        : item.scope_type === 'department'
          ? nameOf(props.departments, item.scope_id)
          : nameOf(props.subdepartments, item.scope_id);
</script>

<template>
    <Head title="Departments and CRM access" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Departments and CRM access"
            description="Organize members and grant extra lead visibility to individuals."
        >
            <template #actions>
                <Link href="/crm/leads" class="text-sm underline">{{
                    t('Back to CRM leads')
                }}</Link>
            </template>
        </PageHeader>
        <div aria-live="polite">
            <InputError
                v-for="(error, field) in page.props.errors"
                :key="field"
                :message="error"
            />
        </div>
        <p class="text-muted-foreground text-sm">
            Owners and Administrators see all leads. Everyone else sees their
            own assigned leads plus explicitly granted scopes. Grants add
            visibility only; existing CRM role permissions still control
            editing.
        </p>
        <div class="grid gap-4 md:grid-cols-3">
            <Card
                ><CardHeader><CardTitle>Departments</CardTitle></CardHeader
                ><CardContent class="space-y-3">
                    <form
                        class="flex gap-2"
                        @submit.prevent="save('department', department)"
                    >
                        <Label class="sr-only" for="department-name"
                            >Department name</Label
                        ><Input
                            id="department-name"
                            v-model="department.name"
                            required
                            placeholder="Department name"
                        /><Button>{{ t('Create') }}</Button>
                    </form>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="item in departments"
                            :key="item.id"
                            class="flex items-center justify-between gap-2"
                        >
                            <span
                                >{{ item.name
                                }}{{ item.active ? '' : ' (archived)' }}</span
                            ><Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="setActive('department', item)"
                                >{{
                                    item.active ? 'Archive' : 'Activate'
                                }}</Button
                            >
                        </li>
                    </ul>
                </CardContent></Card
            >
            <Card
                ><CardHeader><CardTitle>Subdepartments</CardTitle></CardHeader
                ><CardContent class="space-y-3">
                    <form
                        class="space-y-2"
                        @submit.prevent="save('subdepartment', subdepartment)"
                    >
                        <Label for="subdepartment-parent">Department</Label
                        ><select
                            id="subdepartment-parent"
                            v-model="subdepartment.department_id"
                            required
                            class="w-full rounded-md border p-2"
                        >
                            <option :value="null" disabled>
                                Select department
                            </option>
                            <option
                                v-for="item in departments"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option></select
                        ><Label for="subdepartment-name"
                            >Subdepartment name</Label
                        ><Input
                            id="subdepartment-name"
                            v-model="subdepartment.name"
                            required
                        /><Button>{{ t('Create') }}</Button>
                    </form>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="item in subdepartments"
                            :key="item.id"
                            class="flex items-center justify-between gap-2"
                        >
                            <span
                                >{{ item.name }} ·
                                {{ nameOf(departments, item.department_id)
                                }}{{ item.active ? '' : ' (archived)' }}</span
                            ><Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="setActive('subdepartment', item)"
                                >{{
                                    item.active ? 'Archive' : 'Activate'
                                }}</Button
                            >
                        </li>
                    </ul>
                </CardContent></Card
            >
            <Card
                ><CardHeader><CardTitle>Teams</CardTitle></CardHeader
                ><CardContent class="space-y-3">
                    <form
                        class="space-y-2"
                        @submit.prevent="save('team', team)"
                    >
                        <Label for="team-parent">Subdepartment</Label
                        ><select
                            id="team-parent"
                            v-model="team.subdepartment_id"
                            required
                            class="w-full rounded-md border p-2"
                        >
                            <option :value="null" disabled>
                                Select subdepartment
                            </option>
                            <option
                                v-for="item in subdepartments"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option></select
                        ><Label for="team-name">Team name</Label
                        ><Input
                            id="team-name"
                            v-model="team.name"
                            required
                        /><Button>{{ t('Create') }}</Button>
                    </form>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="item in teams"
                            :key="item.id"
                            class="flex items-center justify-between gap-2"
                        >
                            <span
                                >{{ item.name }} ·
                                {{
                                    nameOf(
                                        subdepartments,
                                        item.subdepartment_id,
                                    )
                                }}{{ item.active ? '' : ' (archived)' }}</span
                            ><Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="setActive('team', item)"
                                >{{
                                    item.active ? 'Archive' : 'Activate'
                                }}</Button
                            >
                        </li>
                    </ul>
                </CardContent></Card
            >
        </div>
        <Card
            ><CardHeader><CardTitle>Member placement</CardTitle></CardHeader
            ><CardContent class="space-y-3">
                <p class="text-muted-foreground text-sm">
                    Each member has one primary team. Team members still see
                    only their own leads.
                </p>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="savePlacement"
                >
                    <div>
                        <Label for="placement-member">{{ t('Member') }}</Label
                        ><select
                            id="placement-member"
                            v-model="placement.user_id"
                            required
                            class="block rounded-md border p-2"
                        >
                            <option :value="null" disabled>
                                Select member
                            </option>
                            <option
                                v-for="item in members"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <Label for="placement-team">Team</Label
                        ><select
                            id="placement-team"
                            v-model="placement.team_id"
                            class="block rounded-md border p-2"
                        >
                            <option :value="null">No team</option>
                            <option
                                v-for="item in teams"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                    </div>
                    <Button>Save placement</Button>
                </form>
                <ul class="text-sm">
                    <li v-for="item in placements" :key="item.user_id">
                        {{
                            members.find((member) => member.id === item.user_id)
                                ?.name
                        }}
                        → {{ nameOf(teams, item.team_id) }}
                    </li>
                </ul>
            </CardContent></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Individual visibility grants</CardTitle></CardHeader
            ><CardContent class="space-y-3">
                <p class="text-muted-foreground text-sm">
                    Assign a department, subdepartment, or whole-organization
                    view to any member. Department grants include its
                    subdepartments. A Manager with no grant sees only their own
                    leads.
                </p>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="saveGrant"
                >
                    <div>
                        <Label for="grant-member">{{ t('Member') }}</Label
                        ><select
                            id="grant-member"
                            v-model="grant.user_id"
                            required
                            class="block rounded-md border p-2"
                        >
                            <option :value="null" disabled>
                                Select member
                            </option>
                            <option
                                v-for="item in members"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }} ({{ item.role }})
                            </option>
                        </select>
                    </div>
                    <div>
                        <Label for="grant-type">Scope</Label
                        ><select
                            id="grant-type"
                            v-model="grant.scope_type"
                            class="block rounded-md border p-2"
                        >
                            <option value="department">Department</option>
                            <option value="subdepartment">Subdepartment</option>
                            <option value="organization">
                                Whole organization
                            </option>
                        </select>
                    </div>
                    <div v-if="grant.scope_type !== 'organization'">
                        <Label for="grant-scope">Area</Label
                        ><select
                            id="grant-scope"
                            v-model="grant.scope_id"
                            required
                            class="block rounded-md border p-2"
                        >
                            <option :value="null" disabled>Select area</option>
                            <option
                                v-for="item in grant.scope_type === 'department'
                                    ? departments
                                    : subdepartments"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                    </div>
                    <Button>Grant view access</Button>
                </form>
                <ul class="space-y-2 text-sm">
                    <li
                        v-for="item in grants"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <span
                            >{{
                                members.find(
                                    (member) => member.id === item.user_id,
                                )?.name
                            }}
                            → {{ scopeName(item) }}</span
                        ><Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="revoke(item.id)"
                            >{{ t('Revoke') }}</Button
                        >
                    </li>
                </ul>
            </CardContent></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Individual CRM editing</CardTitle></CardHeader
            ><CardContent class="space-y-3">
                <p class="text-muted-foreground text-sm">
                    Allow a Member or Viewer to edit CRM records. Lead actions
                    remain within their existing lead visibility. This does not
                    grant pipeline configuration or access to other
                    organizations.
                </p>
                <ul class="space-y-2 text-sm">
                    <li
                        v-for="member in members.filter(
                            (item) =>
                                item.role === 'member' ||
                                item.role === 'viewer',
                        )"
                        :key="member.id"
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <span
                            >{{ member.name }} ({{ member.role }}) ·
                            {{
                                editGrantUserIds.includes(member.id)
                                    ? 'editing allowed'
                                    : 'view only'
                            }}</span
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="
                                setEditGrant(
                                    member.id,
                                    !editGrantUserIds.includes(member.id),
                                )
                            "
                            >{{
                                editGrantUserIds.includes(member.id)
                                    ? 'Remove edit access'
                                    : 'Allow editing'
                            }}</Button
                        >
                    </li>
                </ul>
            </CardContent></Card
        >
    </div>
</template>
