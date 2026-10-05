<script setup lang="ts">
import {
    ChevronDown,
    ChevronsDownUp,
    ChevronsUpDown,
    Plus,
    Search,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { useLocale } from '@/composables/useLocale';
import {
    LEVELS,
    cellKey,
    countChanges,
    filterGroups,
    getCell,
    setCell,
    toggleCollapsed,
} from '@/lib/crm-permissions';
import type {
    PermissionGroup,
    PermissionRole,
    PermissionValue,
    PermissionValues,
} from '@/lib/crm-permissions';

const props = withDefaults(
    defineProps<{
        groups: PermissionGroup[];
        roles: PermissionRole[];
        values: PermissionValues;
        canEdit?: boolean;
    }>(),
    { canEdit: false },
);
const emit = defineEmits<{ save: [values: PermissionValues]; addRole: [] }>();

const { t } = useLocale();
const draft = ref<PermissionValues>(structuredClone(props.values));
const query = ref('');
const collapsed = ref<string[]>([]);
const visibleGroups = computed(() => filterGroups(props.groups, query.value));
const changes = computed(() =>
    countChanges(props.groups, props.roles, props.values, draft.value),
);

function value(
    roleId: number,
    group: PermissionGroup,
    row: PermissionGroup['rows'][number],
): PermissionValue {
    return getCell(draft.value, roleId, cellKey(group.key, row.key), row.kind);
}
function update(
    roleId: number,
    group: PermissionGroup,
    row: PermissionGroup['rows'][number],
    next: PermissionValue,
): void {
    draft.value = setCell(
        draft.value,
        roleId,
        cellKey(group.key, row.key),
        next,
    );
}
function cancel(): void {
    draft.value = structuredClone(props.values);
}
function collapseAll(collapse: boolean): void {
    collapsed.value = collapse ? props.groups.map((group) => group.key) : [];
}
function jump(key: string): void {
    collapsed.value = collapsed.value.filter((item) => item !== key);
    document
        .getElementById(`perm-group-${key}`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function initials(name: string): string {
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
        <nav :aria-label="t('Permission groups')" class="hidden lg:block">
            <ul class="sticky top-4 space-y-1 text-sm">
                <li v-for="group in groups" :key="group.key">
                    <button
                        type="button"
                        class="hover:bg-muted w-full truncate rounded-md px-3 py-1.5 text-start"
                        @click="jump(group.key)"
                    >
                        {{ group.label }}
                    </button>
                </li>
            </ul>
        </nav>

        <div class="min-w-0 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-56 flex-1 sm:max-w-sm">
                    <Search
                        class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="query"
                        type="search"
                        class="ps-9"
                        :aria-label="t('Search permissions')"
                        :placeholder="t('Search')"
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    :aria-label="t('Collapse all')"
                    @click="collapseAll(true)"
                    ><ChevronsDownUp class="size-4" aria-hidden="true"
                /></Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    :aria-label="t('Expand all')"
                    @click="collapseAll(false)"
                    ><ChevronsUpDown class="size-4" aria-hidden="true"
                /></Button>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full min-w-[40rem] border-collapse text-sm">
                    <caption class="sr-only">
                        {{
                            t('Access permissions by role')
                        }}
                    </caption>
                    <thead>
                        <tr class="bg-muted/40">
                            <th
                                scope="col"
                                class="bg-muted/40 sticky start-0 z-10 w-64 p-3 text-start font-medium"
                            >
                                {{ t('Roles') }}
                            </th>
                            <th
                                v-for="role in roles"
                                :key="role.id"
                                scope="col"
                                class="min-w-44 p-3 text-start align-top"
                            >
                                <p class="font-medium">{{ role.name }}</p>
                                <div
                                    class="mt-1 flex -space-x-1.5 rtl:space-x-reverse"
                                >
                                    <span
                                        v-for="member in role.members.slice(
                                            0,
                                            4,
                                        )"
                                        :key="member.id"
                                        class="bg-primary/10 text-primary ring-background flex size-6 items-center justify-center rounded-full text-[10px] font-semibold ring-2"
                                        :title="member.name"
                                        >{{ initials(member.name) }}</span
                                    >
                                    <span
                                        v-if="role.members.length > 4"
                                        class="bg-muted text-muted-foreground ring-background flex size-6 items-center justify-center rounded-full text-[10px] ring-2"
                                        >+{{ role.members.length - 4 }}</span
                                    >
                                </div>
                            </th>
                            <th scope="col" class="w-16 p-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="rounded-full"
                                    :disabled="!canEdit"
                                    :aria-label="t('Add role')"
                                    @click="emit('addRole')"
                                    ><Plus class="size-4" aria-hidden="true"
                                /></Button>
                            </th>
                        </tr>
                    </thead>
                    <template v-for="group in visibleGroups" :key="group.key">
                        <tbody :id="`perm-group-${group.key}`">
                            <tr class="bg-muted/30 border-t">
                                <th
                                    :colspan="roles.length + 2"
                                    scope="rowgroup"
                                    class="p-0 text-start"
                                >
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 font-medium"
                                        :aria-expanded="
                                            !collapsed.includes(group.key)
                                        "
                                        @click="
                                            collapsed = toggleCollapsed(
                                                collapsed,
                                                group.key,
                                            )
                                        "
                                    >
                                        <ChevronDown
                                            class="size-4 transition-transform"
                                            :class="{
                                                '-rotate-90':
                                                    collapsed.includes(
                                                        group.key,
                                                    ),
                                            }"
                                            aria-hidden="true"
                                        />{{ group.label }}
                                    </button>
                                </th>
                            </tr>
                            <template v-if="!collapsed.includes(group.key)">
                                <tr
                                    v-for="row in group.rows"
                                    :key="row.key"
                                    class="border-t"
                                >
                                    <th
                                        scope="row"
                                        class="bg-background sticky start-0 z-10 p-3 text-start font-normal"
                                    >
                                        {{ t(row.label) }}
                                    </th>
                                    <td
                                        v-for="role in roles"
                                        :key="role.id"
                                        class="p-3"
                                    >
                                        <Switch
                                            v-if="row.kind === 'toggle'"
                                            :model-value="
                                                value(role.id, group, row) ===
                                                true
                                            "
                                            :disabled="!canEdit"
                                            :aria-label="`${row.label}: ${role.name}`"
                                            @update:model-value="
                                                update(
                                                    role.id,
                                                    group,
                                                    row,
                                                    $event,
                                                )
                                            "
                                        />
                                        <select
                                            v-else
                                            :value="value(role.id, group, row)"
                                            :disabled="!canEdit"
                                            :aria-label="`${row.label}: ${role.name}`"
                                            class="text-muted-foreground hover:text-foreground w-full bg-transparent text-sm underline decoration-dotted underline-offset-4 disabled:opacity-60"
                                            @change="
                                                update(
                                                    role.id,
                                                    group,
                                                    row,
                                                    (
                                                        $event.target as HTMLSelectElement
                                                    ).value,
                                                )
                                            "
                                        >
                                            <option
                                                v-for="level in LEVELS"
                                                :key="level.value"
                                                :value="level.value"
                                            >
                                                {{ t(level.label) }}
                                            </option>
                                        </select>
                                    </td>
                                    <td />
                                </tr>
                            </template>
                        </tbody>
                    </template>
                </table>
                <p
                    v-if="!visibleGroups.length"
                    class="text-muted-foreground p-6 text-center text-sm"
                >
                    {{ t('No permissions match your search.') }}
                </p>
            </div>

            <div
                class="bg-background sticky bottom-0 flex flex-wrap items-center gap-2 border-t py-3"
            >
                <Button
                    type="button"
                    :disabled="!canEdit || changes === 0"
                    @click="emit('save', draft)"
                    >{{ t('Save') }}</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="changes === 0"
                    @click="cancel"
                    >{{ t('Cancel') }}</Button
                >
                <span class="text-muted-foreground text-sm" role="status">{{
                    changes
                        ? `${changes} ${t('unsaved changes')}`
                        : t('No changes')
                }}</span>
                <span
                    v-if="!canEdit"
                    class="text-muted-foreground ms-auto text-xs"
                    >{{ t('Saving permissions is not connected yet.') }}</span
                >
            </div>
        </div>
    </div>
</template>
