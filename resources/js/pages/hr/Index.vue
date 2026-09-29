<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Staff = {
    id: number;
    name: string;
    job_title: string | null;
    hired_on: string | null;
    dismissed_on: string | null;
    status: string;
    last_active: number | null;
};
defineProps<{
    staff: Staff[];
    members: { id: number; name: string }[];
    canManage: boolean;
}>();
const form = useForm({
    user_id: '',
    job_title: '',
    hired_on: new Date().toISOString().slice(0, 10),
});
function hire(): void {
    form.post('/hr/staff', {
        onSuccess: () => form.reset('user_id', 'job_title'),
    });
}
function activeAt(value: number | null): string {
    return value
        ? new Date(value * 1000).toLocaleString()
        : 'No recorded session';
}
</script>

<template>
    <Head title="Staff" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Staff"
            description="Employee profiles, employment status and recent activity"
        />
        <Card v-if="canManage"
            ><CardHeader
                ><CardTitle>Hire an organization member</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 sm:grid-cols-[1fr_1fr_180px_auto] sm:items-end"
                    @submit.prevent="hire"
                >
                    <div>
                        <Label for="member">Member</Label
                        ><select
                            id="member"
                            v-model="form.user_id"
                            required
                            class="border-input bg-background h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Choose a member</option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <Label for="job-title">Job title</Label
                        ><Input id="job-title" v-model="form.job_title" />
                    </div>
                    <div>
                        <Label for="hired-on">Hire date</Label
                        ><Input
                            id="hired-on"
                            v-model="form.hired_on"
                            type="date"
                        />
                    </div>
                    <Button :disabled="form.processing">Create profile</Button
                    ><InputError
                        v-for="(error, key) in form.errors"
                        :key="key"
                        :message="error"
                        class="sm:col-span-4"
                    />
                </form>
                <p class="text-muted-foreground mt-3 text-xs">
                    Invite new people through organization members first; hiring
                    creates their staff profile.
                </p></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Employees</CardTitle></CardHeader
            ><CardContent
                ><div class="space-y-2">
                    <Link
                        v-for="person in staff"
                        :key="person.id"
                        :href="`/hr/staff/${person.id}`"
                        class="hover:bg-muted flex flex-wrap items-center justify-between gap-2 rounded-md border p-3"
                        ><div>
                            <p class="font-medium">{{ person.name }}</p>
                            <p class="text-muted-foreground text-sm">
                                {{ person.job_title || 'No job title' }} ·
                                {{ person.status }}
                            </p>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            Last active: {{ activeAt(person.last_active) }}
                        </p></Link
                    >
                    <p
                        v-if="!staff.length"
                        class="text-muted-foreground text-sm"
                    >
                        No staff profiles yet.
                    </p>
                </div></CardContent
            ></Card
        >
    </div>
</template>
