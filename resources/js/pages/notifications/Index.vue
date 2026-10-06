<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

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
const prefsOpen = ref(false);
function save(): void {
    form.put('/notifications/preferences', {
        preserveScroll: true,
        onSuccess: () => (prefsOpen.value = false),
    });
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
    <Head :title="t('Notifications')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Notifications"
            :description="`${unreadCount} ${t('unread')} · ${t('CRM and operational alerts')}`"
            :translate="false"
        >
            <template #actions>
                <Button
                    v-if="unreadCount"
                    type="button"
                    variant="outline"
                    @click="markAllRead"
                    >{{ t('Mark all read') }}</Button
                >
                <Button
                    v-if="canManageSettings"
                    type="button"
                    variant="outline"
                    @click="prefsOpen = true"
                    >{{ t('Delivery preferences') }}</Button
                >
            </template>
        </PageHeader>

        <Card>
            <CardContent class="space-y-1">
                <p
                    v-if="!notifications.data.length"
                    class="text-muted-foreground text-sm"
                >
                    {{
                        t(
                            'No notifications yet. CRM stage entries and daily alerts appear here when action is needed.',
                        )
                    }}
                </p>
                <div
                    v-for="notification in notifications.data"
                    :key="notification.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-b py-3 last:border-0"
                    :class="
                        notification.read_at
                            ? ''
                            : 'bg-primary/5 -mx-2 rounded-md px-2'
                    "
                >
                    <div class="min-w-0">
                        <Link
                            :href="notification.href"
                            class="font-medium hover:underline"
                        >
                            {{ notification.title
                            }}<template
                                v-if="
                                    notification.category !== 'crm_stage_entry'
                                "
                            >
                                · {{ notification.count }}</template
                            >
                        </Link>
                        <p class="text-muted-foreground text-sm">
                            <Badge variant="outline" class="me-2 capitalize">{{
                                label(notification.category)
                            }}</Badge>
                            {{
                                new Date(
                                    notification.created_at,
                                ).toLocaleString()
                            }}
                            ·
                            {{ notification.read_at ? t('Read') : t('Unread') }}
                        </p>
                    </div>
                    <Button
                        v-if="!notification.read_at"
                        size="sm"
                        variant="outline"
                        @click="markRead(notification.id)"
                        >{{ t('Mark read') }}</Button
                    >
                </div>
                <div class="flex justify-between gap-3 pt-3">
                    <Link
                        v-if="notifications.prev_page_url"
                        :href="notifications.prev_page_url"
                        class="text-sm underline"
                        >{{ t('Previous') }}</Link
                    ><span v-else />
                    <Link
                        v-if="notifications.next_page_url"
                        :href="notifications.next_page_url"
                        class="text-sm underline"
                        >{{ t('Next') }}</Link
                    >
                </div>
            </CardContent>
        </Card>

        <Sheet v-model:open="prefsOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Organization delivery preferences')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t('CRM and operational alerts')
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="prefs-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="save"
                >
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="form.daily_digest_enabled"
                            type="checkbox"
                        />{{ t('Enable daily in-app notifications') }}</label
                    >
                    <fieldset class="space-y-2">
                        <legend class="mb-2 text-sm font-medium">
                            {{ t('Alert categories') }}
                        </legend>
                        <label
                            v-for="category in categories"
                            :key="category"
                            class="flex items-center gap-2 text-sm capitalize"
                        >
                            <input
                                v-model="form.enabled_categories"
                                type="checkbox"
                                :value="category"
                            />{{ label(category) }}
                        </label>
                    </fieldset>
                    <p
                        v-if="form.hasErrors"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ t('Check your settings and try again.') }}
                    </p>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="prefs-form"
                        :disabled="form.processing"
                        >{{ t('Save preferences') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="prefsOpen = false"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>
    </div>
</template>
