<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
const props = defineProps<{
    root: {
        id: number;
        name: string;
        archived_at: string | null;
        archive_reason: string | null;
    };
    canEdit: boolean;
    versions: {
        id: number;
        name: string;
        mime_type: string;
        size: number;
        version_number: number;
        version_reason: string | null;
        created_at: string;
    }[];
}>();
const upload = useForm({
    file: null as File | null,
    reason: '',
    version_key: crypto.randomUUID(),
});
const lifecycle = useForm({ reason: '' });
function select(event: Event): void {
    upload.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function save(): void {
    upload.post(`/documents/${props.root.id}/versions`, {
        preserveScroll: true,
        onSuccess: () => {
            upload.reset();
            upload.version_key = crypto.randomUUID();
        },
    });
}
function archive(): void {
    lifecycle.post(
        `/documents/${props.root.id}/${props.root.archived_at ? 'restore' : 'archive'}`,
        { preserveScroll: true, onSuccess: () => lifecycle.reset() },
    );
}
</script>
<template>
    <Head :title="t('Document versions')" />
    <div class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-6">
        <Heading
            :translate-text="false"
            :title="root.name"
            :description="t('Private document versions and lifecycle history.')"
        /><Link href="/dashboard" class="text-sm underline">{{
            t('Dashboard')
        }}</Link>
        <p v-if="root.archived_at" class="rounded-md border p-3 text-sm">
            {{ t('Archived') }} {{ root.archived_at }} ·
            {{ root.archive_reason }}
        </p>
        <Card
            ><CardHeader
                ><CardTitle>{{
                    t('Preserved versions')
                }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><article
                    v-for="version in versions"
                    :key="version.id"
                    class="space-y-1 border-b pb-3 text-sm"
                >
                    <p>
                        {{ t('Version') }} {{ version.version_number }} ·
                        {{ version.name }} ·
                        {{ Math.ceil(version.size / 1024) }} {{ t('KB') }} ·
                        {{ version.created_at }}
                    </p>
                    <p v-if="version.version_reason">
                        {{ version.version_reason }}
                    </p>
                    <div class="flex gap-3">
                        <a
                            :href="`/documents/${version.id}/download`"
                            class="underline"
                            >{{ t('Download') }}</a
                        ><a
                            v-if="
                                [
                                    'application/pdf',
                                    'image/jpeg',
                                    'image/png',
                                    'image/webp',
                                ].includes(version.mime_type)
                            "
                            :href="`/documents/${version.id}/preview`"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline"
                            >{{ t('Preview') }}</a
                        >
                    </div>
                </article></CardContent
            ></Card
        ><Card v-if="canEdit && !root.archived_at"
            ><CardHeader
                ><CardTitle>{{ t('Add a version') }}</CardTitle></CardHeader
            ><CardContent
                ><form class="space-y-3" @submit.prevent="save">
                    <label class="block text-sm"
                        >{{ t('File')
                        }}<Input type="file" required @change="select" /></label
                    ><label class="block text-sm"
                        >{{ t('Version reason')
                        }}<Input
                            v-model="upload.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in upload.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button :disabled="upload.processing">{{
                        t('Preserve new version')
                    }}</Button>
                </form></CardContent
            ></Card
        ><Card v-if="canEdit"
            ><CardHeader
                ><CardTitle>{{
                    root.archived_at
                        ? t('Restore document')
                        : t('Archive document')
                }}</CardTitle></CardHeader
            ><CardContent
                ><p class="text-muted-foreground mb-3 text-sm">
                    {{
                        t(
                            'Archiving retains every version and file. Permanent deletion is disabled.',
                        )
                    }}
                </p>
                <form class="space-y-3" @submit.prevent="archive">
                    <label class="block text-sm"
                        >{{ t('Reason')
                        }}<Input
                            v-model="lifecycle.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in lifecycle.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        variant="outline"
                        :disabled="lifecycle.processing"
                        >{{
                            root.archived_at ? t('Restore') : t('Archive')
                        }}</Button
                    >
                </form></CardContent
            ></Card
        >
    </div>
</template>
