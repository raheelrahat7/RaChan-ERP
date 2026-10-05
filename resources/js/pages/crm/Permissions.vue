<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import CrmPermissionsMatrix from '@/components/CrmPermissionsMatrix.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import {
    SCOPE_LEVELS,
    accessRequests,
    collectPrincipals,
    dealGroups,
    dealValues,
    principalKey,
    sectionGroups,
    sectionRequests,
    sectionValues,
    toRoles,
} from '@/lib/crm-access';
import type {
    AccessRule,
    Lookups,
    Principal,
    PrincipalType,
    SectionRule,
} from '@/lib/crm-access';
import { ApiError, apiJson } from '@/lib/crm-api';
import type { PermissionValues } from '@/lib/crm-permissions';

type Config = {
    pipelines: { id: number; name: string }[];
    accessRules: AccessRule[];
};
type Settings = Lookups & {
    roles: string[];
    sectionPermissions: string[];
    sectionAccess: SectionRule[];
};

const { t } = useLocale();
const tab = ref<'deals' | 'sections'>('deals');
const config = ref<Config | null>(null);
const settings = ref<Settings | null>(null);
const extra = ref<Principal[]>([]);
const loadError = ref('');
const saveError = ref('');
const saved = ref('');
const saving = ref(false);
const addOpen = ref(false);
const addType = ref<PrincipalType>('role');
const addId = ref('');

const lookups = computed<Lookups>(() => ({
    members: settings.value?.members ?? [],
    teams: settings.value?.teams ?? [],
    subdepartments: settings.value?.subdepartments ?? [],
    departments: settings.value?.departments ?? [],
}));
const pipelines = computed(() => config.value?.pipelines ?? []);
const permissions = computed(() => settings.value?.sectionPermissions ?? []);
const dealPrincipals = computed(() =>
    collectPrincipals(config.value?.accessRules ?? [], extra.value),
);
const sectionPrincipals = computed(() =>
    collectPrincipals(settings.value?.sectionAccess ?? [], extra.value),
);
const principals = computed(() =>
    tab.value === 'deals' ? dealPrincipals.value : sectionPrincipals.value,
);
const roles = computed(() => toRoles(principals.value, lookups.value));
const groups = computed(() =>
    tab.value === 'deals'
        ? dealGroups(pipelines.value)
        : sectionGroups(permissions.value),
);
const values = computed<PermissionValues>(() =>
    tab.value === 'deals'
        ? dealValues(
              pipelines.value,
              dealPrincipals.value,
              config.value?.accessRules ?? [],
          )
        : sectionValues(
              permissions.value,
              sectionPrincipals.value,
              settings.value?.sectionAccess ?? [],
          ),
);
const choices = computed(() => {
    const byType: Record<PrincipalType, { id: string; name: string }[]> = {
        role: ['manager', 'member', 'viewer'].map((id) => ({
            id,
            name: id.charAt(0).toUpperCase() + id.slice(1),
        })),
        user: lookups.value.members.map((item) => ({
            id: String(item.id),
            name: item.name,
        })),
        team: lookups.value.teams.map((item) => ({
            id: String(item.id),
            name: item.name,
        })),
        subdepartment: lookups.value.subdepartments.map((item) => ({
            id: String(item.id),
            name: item.name,
        })),
        department: lookups.value.departments.map((item) => ({
            id: String(item.id),
            name: item.name,
        })),
    };
    const taken = new Set(
        principals.value.map((principal) =>
            principalKey(principal.type, principal.id),
        ),
    );

    return byType[addType.value].filter(
        (item) => !taken.has(principalKey(addType.value, item.id)),
    );
});

async function load(): Promise<void> {
    loadError.value = '';
    try {
        [config.value, settings.value] = await Promise.all([
            apiJson<Config>('/crm/deals/configuration'),
            apiJson<Settings>('/crm/settings/data'),
        ]);
    } catch (cause) {
        loadError.value =
            cause instanceof ApiError
                ? cause.message
                : t('Something went wrong. Please try again.');
    }
}
function addPrincipal(): void {
    if (addId.value) {
        extra.value = [
            ...extra.value,
            { type: addType.value, id: addId.value },
        ];
    }
    addOpen.value = false;
    addId.value = '';
}
async function save(next: PermissionValues): Promise<void> {
    saving.value = true;
    saveError.value = '';
    saved.value = '';
    try {
        if (tab.value === 'deals') {
            for (const request of accessRequests(
                pipelines.value,
                principals.value,
                values.value,
                next,
            )) {
                await apiJson(
                    `/crm/deals/pipelines/${request.pipelineId}/access`,
                    'PUT',
                    request.body,
                );
            }
        } else {
            for (const request of sectionRequests(
                permissions.value,
                principals.value,
                values.value,
                next,
            )) {
                await apiJson('/crm/settings/section-access', 'PUT', request);
            }
        }
        saved.value = t('Permissions saved.');
        await load();
    } catch (cause) {
        saveError.value =
            cause instanceof ApiError
                ? cause.message
                : t('Something went wrong. Please try again.');
        await load();
    } finally {
        saving.value = false;
    }
}
onMounted(load);
</script>

<template>
    <Head :title="t('Access permissions')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="t('Access permissions')" :translate="false">
            <template #actions
                ><Link href="/crm/settings" class="text-sm underline">{{
                    t('CRM settings')
                }}</Link></template
            >
        </PageHeader>

        <div
            class="flex flex-wrap items-center gap-2"
            role="tablist"
            :aria-label="t('Permission areas')"
        >
            <Button
                type="button"
                size="sm"
                role="tab"
                :aria-selected="tab === 'deals'"
                :variant="tab === 'deals' ? 'default' : 'outline'"
                @click="tab = 'deals'"
                >{{ t('Deal pipelines') }}</Button
            >
            <Button
                type="button"
                size="sm"
                role="tab"
                :aria-selected="tab === 'sections'"
                :variant="tab === 'sections' ? 'default' : 'outline'"
                @click="tab = 'sections'"
                >{{ t('CRM sections') }}</Button
            >
        </div>
        <p class="text-muted-foreground max-w-3xl text-sm">
            <template v-if="tab === 'deals'">{{
                t(
                    'Owners and administrators always have full access. Once a pipeline has any rule, only the people and groups listed here can use it; a pipeline with no rules lets people see and edit their own deals. Rules add up: a person gets everything any of their roles or groups allows.',
                )
            }}</template>
            <template v-else>{{
                t(
                    'Every section is on unless switched off here. Switching a section off removes it for that role, person or group, on top of what their role already allows.',
                )
            }}</template>
        </p>

        <p v-if="loadError" class="text-destructive text-sm" role="alert">
            {{ loadError }}
        </p>
        <p
            v-else-if="!config || !settings"
            class="text-muted-foreground text-sm"
            role="status"
        >
            {{ t('Loading…') }}
        </p>
        <template v-else>
            <p v-if="saved" class="text-sm text-emerald-700" role="status">
                {{ saved }}
            </p>
            <div aria-live="polite"><InputError :message="saveError" /></div>
            <p
                v-if="tab === 'deals' && !pipelines.length"
                class="text-muted-foreground rounded-md border border-dashed p-6 text-sm"
            >
                {{
                    t(
                        'There are no deal pipelines yet. Create one first, then set who can use it.',
                    )
                }}
            </p>
            <CrmPermissionsMatrix
                v-else
                :key="tab"
                :groups="groups"
                :roles="roles"
                :values="values"
                :levels="SCOPE_LEVELS"
                default-level="none"
                :default-toggle="true"
                :can-edit="!saving"
                @save="save"
                @add-role="addOpen = true"
            />
        </template>

        <Dialog v-model:open="addOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{ t('Add a column') }}</DialogTitle
                    ><DialogDescription>{{
                        t(
                            'Choose a role, a person or a group to set permissions for.',
                        )
                    }}</DialogDescription></DialogHeader
                >
                <div class="space-y-3">
                    <div class="space-y-1">
                        <Label for="perm-type">{{ t('Type') }}</Label>
                        <select
                            id="perm-type"
                            v-model="addType"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            @change="addId = ''"
                        >
                            <option value="role">{{ t('Role') }}</option>
                            <option value="user">{{ t('Person') }}</option>
                            <option value="team">{{ t('Team') }}</option>
                            <option value="subdepartment">
                                {{ t('Sub-department') }}
                            </option>
                            <option value="department">
                                {{ t('Department') }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="perm-who">{{ t('Who') }}</Label>
                        <select
                            id="perm-who"
                            v-model="addId"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option value="" disabled>
                                {{ t('Choose…') }}
                            </option>
                            <option
                                v-for="item in choices"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                    </div>
                </div>
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="addOpen = false"
                        >{{ t('Cancel') }}</Button
                    ><Button
                        type="button"
                        :disabled="!addId"
                        @click="addPrincipal"
                        >{{ t('Add') }}</Button
                    ></DialogFooter
                >
            </DialogContent>
        </Dialog>
    </div>
</template>
