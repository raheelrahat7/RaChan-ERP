<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import CrmStageRulesEditor from '@/components/CrmStageRulesEditor.vue';
import CrmStageNotificationEditor from '@/components/CrmStageNotificationEditor.vue';
import CrmStageFollowUpEditor from '@/components/CrmStageFollowUpEditor.vue';
import CrmStageAssignmentEditor from '@/components/CrmStageAssignmentEditor.vue';
import CrmConfigurationEditor from '@/components/CrmConfigurationEditor.vue';
import InputError from '@/components/InputError.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import type { Pipeline } from '@/types/crm-pipeline';
defineProps<{
    pipelines: Pipeline[];
    members: { id: number; name: string }[];
}>();
const page = usePage();
</script>
<template>
    <Head title="CRM pipeline management" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="CRM pipeline management"
            description="Configure organization pipelines, stages and lost reasons."
        />
        <Link href="/crm/leads" class="text-sm underline">Back to leads</Link>
        <div aria-live="polite">
            <InputError
                v-for="(error, field) in page.props.errors"
                :key="field"
                :message="error"
            />
        </div>
        <Card
            ><CardHeader><CardTitle>Add pipeline</CardTitle></CardHeader
            ><CardContent
                ><CrmConfigurationEditor kind="pipeline" /></CardContent
        ></Card>
        <Card v-for="pipeline in pipelines" :key="pipeline.id">
            <CardHeader
                ><CardTitle
                    >{{ pipeline.name }}
                    {{ pipeline.is_default ? '(default)' : '' }}</CardTitle
                ></CardHeader
            >
            <CardContent class="space-y-5">
                <CrmConfigurationEditor
                    :key="`pipeline-${pipeline.id}`"
                    kind="pipeline"
                    :item="pipeline"
                />
                <p class="text-muted-foreground text-sm">
                    Used stages keep their outcome type. The initial stage must
                    remain active and normal. Archive used configuration to
                    retain history.
                </p>
                <h3 class="font-medium">Stages</h3>
                <details
                    v-for="stage in pipeline.stages"
                    :key="stage.id"
                    class="rounded-md border p-3"
                >
                    <summary class="cursor-pointer">
                        {{ stage.position }}. {{ stage.name }} ·
                        {{ stage.type }}
                        {{ stage.is_initial ? '· initial' : '' }}
                        {{ !stage.active ? '· inactive' : '' }}
                    </summary>
                    <CrmConfigurationEditor
                        class="mt-3"
                        kind="stage"
                        :pipeline-id="pipeline.id"
                        :item="stage"
                    />
                    <CrmStageRulesEditor :pipeline="pipeline" :stage="stage" />
                    <CrmStageNotificationEditor
                        :pipeline-id="pipeline.id"
                        :stage="stage"
                    />
                    <CrmStageFollowUpEditor
                        :pipeline-id="pipeline.id"
                        :stage="stage"
                    />
                    <CrmStageAssignmentEditor
                        :pipeline-id="pipeline.id"
                        :stage="stage"
                        :members="members"
                    />
                </details>
                <CrmConfigurationEditor
                    kind="stage"
                    :pipeline-id="pipeline.id"
                />
                <h3 class="font-medium">Lost reasons</h3>
                <details
                    v-for="reason in pipeline.reasons"
                    :key="reason.id"
                    class="rounded-md border p-3"
                >
                    <summary class="cursor-pointer">
                        {{ reason.position }}. {{ reason.name }}
                        {{ !reason.active ? '· inactive' : '' }}
                    </summary>
                    <CrmConfigurationEditor
                        class="mt-3"
                        kind="reason"
                        :pipeline-id="pipeline.id"
                        :item="reason"
                    />
                </details>
                <CrmConfigurationEditor
                    kind="reason"
                    :pipeline-id="pipeline.id"
                />
            </CardContent>
        </Card>
    </div>
</template>
