<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';

type Staff = {
    id: number;
    name: string;
    email: string;
    job_title: string | null;
    hired_on: string | null;
    dismissed_on: string | null;
    dismissal_reason: string | null;
    status: string;
    last_active: number | null;
};
const { t } = useLocale();
const props = defineProps<{
    staff: Staff;
    canManage: boolean;
    canDismiss: boolean;
    documents: { id: number; type: string; expires_on: string }[];
    leaveRequests: {
        id: number;
        type: string;
        starts_on: string;
        ends_on: string;
        status: string;
    }[];
}>();
const form = useForm({
    dismissed_on: new Date().toISOString().slice(0, 10),
    reason: '',
});
function dismiss(): void {
    if (confirm(`Dismiss ${props.staff.name} and revoke organization access?`))
        form.post(`/hr/staff/${props.staff.id}/dismiss`);
}
</script>

<template>
    <Head :title="staff.name" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="staff.name"
            :description="staff.job_title || t('Employee profile')"
            :translate="false"
        >
            <template #actions>
                <Link href="/hr" class="text-sm underline">{{
                    t('Back to staff')
                }}</Link>
            </template>
        </PageHeader>
        <div class="grid gap-6 md:grid-cols-2">
            <Card
                ><CardHeader><CardTitle>Employment</CardTitle></CardHeader
                ><CardContent class="space-y-2 text-sm"
                    ><p>Email: {{ staff.email }}</p>
                    <p>Status: {{ staff.status }}</p>
                    <p>Hired: {{ staff.hired_on || 'Not recorded' }}</p>
                    <p>
                        Last active:
                        {{
                            staff.last_active
                                ? new Date(
                                      staff.last_active * 1000,
                                  ).toLocaleString()
                                : 'No recorded session'
                        }}
                    </p>
                    <p v-if="staff.dismissed_on">
                        Dismissed: {{ staff.dismissed_on }}
                    </p>
                    <p v-if="staff.dismissal_reason">
                        Reason: {{ staff.dismissal_reason }}
                    </p></CardContent
                ></Card
            ><Card v-if="canDismiss"
                ><CardHeader><CardTitle>Dismiss employee</CardTitle></CardHeader
                ><CardContent
                    ><form class="space-y-3" @submit.prevent="dismiss">
                        <div>
                            <Label for="dismissed-on">Effective date</Label
                            ><Input
                                id="dismissed-on"
                                v-model="form.dismissed_on"
                                type="date"
                                required
                            />
                        </div>
                        <div>
                            <Label for="reason">Reason</Label
                            ><Input
                                id="reason"
                                v-model="form.reason"
                                required
                                maxlength="2000"
                            />
                        </div>
                        <InputError
                            v-for="(error, key) in form.errors"
                            :key="key"
                            :message="error"
                        />
                        <p class="text-muted-foreground text-xs">
                            Dismissal revokes organization membership and CRM
                            access.
                        </p>
                        <Button
                            variant="destructive"
                            :disabled="form.processing"
                            >Dismiss</Button
                        >
                    </form></CardContent
                ></Card
            >
        </div>
        <div class="grid gap-6 md:grid-cols-2">
            <Card
                ><CardHeader><CardTitle>Documents</CardTitle></CardHeader
                ><CardContent class="space-y-2 text-sm"
                    ><p v-for="item in documents" :key="item.id">
                        {{ item.type }} · expires {{ item.expires_on }}
                    </p>
                    <p v-if="!documents.length" class="text-muted-foreground">
                        No documents recorded.
                    </p></CardContent
                ></Card
            ><Card
                ><CardHeader><CardTitle>Leave</CardTitle></CardHeader
                ><CardContent class="space-y-2 text-sm"
                    ><p v-for="item in leaveRequests" :key="item.id">
                        {{ item.type }} · {{ item.starts_on }}–{{
                            item.ends_on
                        }}
                        · {{ item.status }}
                    </p>
                    <p
                        v-if="!leaveRequests.length"
                        class="text-muted-foreground"
                    >
                        No leave requests.
                    </p></CardContent
                ></Card
            >
        </div>
    </div>
</template>
