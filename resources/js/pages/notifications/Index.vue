<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Notification = {
    id: number;
    category: string;
    title: string;
    count: number;
    href: string;
    read_at: string | null;
    created_at: string;
};
const props = defineProps<{
    notifications: {
        data: Notification[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    unreadCount: number;
    preferences: {
        daily_digest_enabled: boolean;
        enabled_categories: string[];
    };
    categories: string[];
    canManageSettings: boolean;
}>();
const form = useForm({
    daily_digest_enabled: props.preferences.daily_digest_enabled,
    enabled_categories: [...props.preferences.enabled_categories],
});
function save(): void {
    form.put('/notifications/preferences', { preserveScroll: true });
}
function markRead(id: number): void {
    router.post(`/notifications/${id}/read`, {}, { preserveScroll: true });
}
function markAllRead(): void {
    router.post('/notifications/read-all', {}, { preserveScroll: true });
}
function label(category: string): string {
    return category.replaceAll('_', ' ');
}
</script>

<template>
    <Head title="Notifications" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Notifications"
            :description="`${unreadCount} unread · CRM and operational alerts`"
        />
        <Card v-if="canManageSettings">
            <CardHeader
                ><CardTitle
                    >Organization delivery preferences</CardTitle
                ></CardHeader
            >
            <CardContent>
                <form class="space-y-4" @submit.prevent="save">
                    <label class="flex items-center gap-2"
                        ><input
                            v-model="form.daily_digest_enabled"
                            type="checkbox"
                        />Enable daily in-app notifications</label
                    >
                    <fieldset class="space-y-2">
                        <legend class="mb-2 font-medium">
                            Alert categories
                        </legend>
                        <label
                            v-for="category in categories"
                            :key="category"
                            class="flex items-center gap-2 capitalize"
                            ><input
                                v-model="form.enabled_categories"
                                type="checkbox"
                                :value="category"
                            />{{ label(category) }}</label
                        >
                    </fieldset>
                    <p
                        v-if="form.hasErrors"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        Check your settings and try again.
                    </p>
                    <Button :disabled="form.processing"
                        >Save preferences</Button
                    >
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader class="flex flex-row items-center justify-between"
                ><CardTitle>History</CardTitle
                ><Button
                    v-if="unreadCount"
                    size="sm"
                    variant="outline"
                    @click="markAllRead"
                    >Mark all read</Button
                ></CardHeader
            >
            <CardContent class="space-y-3">
                <p
                    v-if="!notifications.data.length"
                    class="text-muted-foreground text-sm"
                >
                    No notifications yet. CRM stage entries and daily alerts
                    appear here when action is needed.
                </p>
                <div
                    v-for="notification in notifications.data"
                    :key="notification.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-b pb-3 last:border-0"
                >
                    <div>
                        <Link
                            :href="notification.href"
                            class="font-medium hover:underline"
                            >{{ notification.title
                            }}<template
                                v-if="
                                    notification.category !== 'crm_stage_entry'
                                "
                            >
                                · {{ notification.count }}</template
                            ></Link
                        >
                        <p class="text-muted-foreground text-sm">
                            {{
                                new Date(
                                    notification.created_at,
                                ).toLocaleString()
                            }}
                            · {{ notification.read_at ? 'Read' : 'Unread' }}
                        </p>
                    </div>
                    <Button
                        v-if="!notification.read_at"
                        size="sm"
                        variant="outline"
                        @click="markRead(notification.id)"
                        >Mark read</Button
                    >
                </div>
                <div class="flex justify-between gap-3">
                    <Link
                        v-if="notifications.prev_page_url"
                        :href="notifications.prev_page_url"
                        class="text-sm underline"
                        >{{ t('Previous') }}</Link
                    ><span v-else /><Link
                        v-if="notifications.next_page_url"
                        :href="notifications.next_page_url"
                        class="text-sm underline"
                        >{{ t('Next') }}</Link
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
