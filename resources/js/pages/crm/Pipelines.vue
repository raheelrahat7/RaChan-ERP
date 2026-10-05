<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import CrmConfigurationEditor from '@/components/CrmConfigurationEditor.vue';
import CrmPipelinePreview from '@/components/CrmPipelinePreview.vue';
import CrmPipelineStageList from '@/components/CrmPipelineStageList.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { useLocale } from '@/composables/useLocale';
import { groupStages } from '@/lib/crm-pipeline-editor';
import type { Pipeline } from '@/types/crm-pipeline';

const props = defineProps<{
    pipelines: Pipeline[];
    members: { id: number; name: string }[];
}>();
const { t } = useLocale();
const page = usePage();
const selectedId = ref(
    props.pipelines.find((item) => item.is_default)?.id ??
        props.pipelines[0]?.id ??
        0,
);
const adding = ref(false);
const selected = computed(() =>
    props.pipelines.find((item) => item.id === selectedId.value),
);
const firstColor = (pipeline: Pipeline): string =>
    groupStages(pipeline.stages).initial?.color ?? '#64748b';
</script>

<template>
    <Head :title="t('CRM pipeline management')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Pipelines and stages')"
            :description="
                t('Configure organization pipelines, stages and lost reasons.')
            "
            :translate="false"
        >
            <template #actions>
                <Link href="/crm/leads" class="text-sm underline">{{
                    t('Back to leads')
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

        <div class="grid gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
            <nav :aria-label="t('Pipelines')" class="space-y-4">
                <div>
                    <p class="text-eyebrow mb-2">{{ t('Lead pipelines') }}</p>
                    <ul class="space-y-1">
                        <li v-for="pipeline in pipelines" :key="pipeline.id">
                            <button
                                type="button"
                                class="hover:bg-muted flex w-full items-center gap-2 rounded-md px-3 py-2 text-start text-sm"
                                :class="
                                    pipeline.id === selectedId
                                        ? 'bg-muted font-medium'
                                        : ''
                                "
                                :aria-current="
                                    pipeline.id === selectedId
                                        ? 'true'
                                        : undefined
                                "
                                @click="
                                    selectedId = pipeline.id;
                                    adding = false;
                                "
                            >
                                <span
                                    class="size-2.5 shrink-0 rounded-full"
                                    :style="{
                                        backgroundColor: firstColor(pipeline),
                                    }"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    pipeline.name
                                }}</span>
                                <Badge
                                    v-if="pipeline.is_default"
                                    variant="secondary"
                                    >{{ t('default') }}</Badge
                                >
                                <Badge
                                    v-if="!pipeline.active"
                                    variant="outline"
                                    >{{ t('inactive') }}</Badge
                                >
                            </button>
                        </li>
                    </ul>
                    <button
                        type="button"
                        class="text-primary mt-2 inline-flex items-center gap-1 px-3 text-xs hover:underline"
                        :aria-expanded="adding"
                        @click="adding = !adding"
                    >
                        <Plus class="size-3.5" aria-hidden="true" />{{
                            t('Add pipeline')
                        }}
                    </button>
                </div>
                <div>
                    <p class="text-eyebrow mb-2">{{ t('Deal pipelines') }}</p>
                    <p
                        class="text-muted-foreground rounded-md border border-dashed p-3 text-xs"
                    >
                        {{
                            t(
                                'Deal pipelines appear here once deals are connected. Each pipeline will have its own stages and its own team access.',
                            )
                        }}
                    </p>
                </div>
            </nav>

            <div class="min-w-0 space-y-6">
                <Card v-if="adding">
                    <CardContent class="space-y-3">
                        <h2 class="text-eyebrow">{{ t('Add pipeline') }}</h2>
                        <CrmConfigurationEditor kind="pipeline" />
                    </CardContent>
                </Card>

                <template v-if="selected">
                    <Card>
                        <CardContent class="space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-display text-2xl font-medium">
                                    {{ selected.name }}
                                </h2>
                                <Badge
                                    v-if="selected.is_default"
                                    variant="secondary"
                                    >{{ t('default') }}</Badge
                                >
                                <Badge
                                    v-if="!selected.active"
                                    variant="outline"
                                    >{{ t('inactive') }}</Badge
                                >
                            </div>
                            <details class="rounded-md border p-3">
                                <summary
                                    class="cursor-pointer text-sm font-medium"
                                >
                                    {{ t('Pipeline settings') }}
                                </summary>
                                <CrmConfigurationEditor
                                    :key="`pipeline-${selected.id}`"
                                    class="mt-3"
                                    kind="pipeline"
                                    :item="selected"
                                />
                                <p class="text-muted-foreground mt-3 text-sm">
                                    {{
                                        t(
                                            'Used stages keep their outcome type. The initial stage must remain active and normal. Archive used configuration to retain history.',
                                        )
                                    }}
                                </p>
                            </details>
                        </CardContent>
                    </Card>

                    <CrmPipelineStageList
                        :key="`stages-${selected.id}`"
                        :pipeline="selected"
                        :members="members"
                    />
                    <CrmPipelinePreview :stages="selected.stages" />

                    <Card>
                        <CardContent class="space-y-3">
                            <h2 class="text-eyebrow">
                                {{ t('Lost reasons') }}
                            </h2>
                            <p
                                v-if="!selected.reasons.length"
                                class="text-muted-foreground text-sm"
                            >
                                {{ t('No lost reasons yet.') }}
                            </p>
                            <details
                                v-for="reason in selected.reasons"
                                :key="reason.id"
                                class="rounded-md border p-3"
                            >
                                <summary class="cursor-pointer text-sm">
                                    {{ reason.position }}. {{ reason.name }}
                                    <span
                                        v-if="!reason.active"
                                        class="text-muted-foreground"
                                        >· {{ t('inactive') }}</span
                                    >
                                </summary>
                                <CrmConfigurationEditor
                                    class="mt-3"
                                    kind="reason"
                                    :pipeline-id="selected.id"
                                    :item="reason"
                                />
                            </details>
                            <details class="rounded-md border p-3">
                                <summary
                                    class="text-primary cursor-pointer text-sm"
                                >
                                    {{ t('Add lost reason') }}
                                </summary>
                                <CrmConfigurationEditor
                                    class="mt-3"
                                    kind="reason"
                                    :pipeline-id="selected.id"
                                />
                            </details>
                        </CardContent>
                    </Card>
                </template>
                <p v-else class="text-muted-foreground text-sm">
                    {{ t('No pipelines yet.') }}
                </p>
            </div>
        </div>
    </div>
</template>
