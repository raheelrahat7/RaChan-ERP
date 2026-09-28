<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
defineProps<{
    documents: { id: number; name: string; version_number: number }[];
    requests: {
        data: {
            id: number;
            document_id: number;
            version_number: number;
            content_hash: string;
            signers: string[];
            status: string;
            reason: string;
            cancellation_reason: string | null;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();
const signerText = ref('');
const form = useForm({
    document_id: '',
    signers: [] as string[],
    reason: '',
    operation_key: crypto.randomUUID(),
});
const cancellation = useForm({ signature_id: '', reason: '' });
function prepare(): void {
    form.signers = signerText.value
        .split('\n')
        .map((value) => value.trim())
        .filter(Boolean);
    form.post('/documents/signatures', {
        onSuccess: () => {
            form.reset();
            form.operation_key = crypto.randomUUID();
            signerText.value = '';
        },
    });
}
function cancel(): void {
    cancellation.post(
        `/documents/signatures/${cancellation.signature_id}/cancel`,
        { onSuccess: () => cancellation.reset() },
    );
}
</script>
<template>
    <Head title="Signature requests" />
    <div class="mx-auto max-w-5xl space-y-6 p-4 md:p-6">
        <Heading
            title="Signature requests"
            description="Prepare a signing request for an exact PDF version and preserve its audit history."
        /><Link href="/organization" class="underline">{{
            t('Organization settings')
        }}</Link>
        <p role="status" class="rounded border p-4">
            {{
                t(
                    'No signature provider is selected. Requests are prepared and validated locally. No invitations are sent and no document is marked signed. An owner may cancel a request with a reason.',
                )
            }}
        </p>
        <form class="space-y-4 rounded border p-4" @submit.prevent="prepare">
            <label class="block"
                >{{ t('PDF version (latest 100 documents)')
                }}<select
                    v-model="form.document_id"
                    required
                    class="block w-full rounded border p-2"
                >
                    <option value="">{{ t('Select a document') }}</option>
                    <option
                        v-for="document in documents"
                        :key="document.id"
                        :value="document.id"
                    >
                        #{{ document.id }} · {{ document.name }} · Version
                        {{ document.version_number }}
                    </option>
                </select></label
            ><label class="block"
                >{{ t('Signer emails, one per line (up to 10)')
                }}<textarea
                    v-model="signerText"
                    required
                    class="block w-full rounded border p-2"
                /></label
            ><label class="block"
                >{{ t('Reason')
                }}<textarea
                    v-model="form.reason"
                    required
                    maxlength="2000"
                    class="block w-full rounded border p-2"
                />
            </label>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                role="alert"
                class="text-destructive"
            >
                {{ error }}
            </p>
            <Button :disabled="form.processing">{{
                t('Prepare request')
            }}</Button>
        </form>
        <ul class="space-y-3">
            <li
                v-for="request in requests.data"
                :key="request.id"
                class="space-y-2 rounded border p-4"
            >
                <h2 class="font-semibold">
                    Request #{{ request.id }} · {{ request.status }}
                </h2>
                <Link
                    :href="`/documents/${request.document_id}/versions`"
                    class="underline"
                    >Document #{{ request.document_id }}, version
                    {{ request.version_number }}</Link
                >
                <p>{{ request.signers.join(', ') }}</p>
                <p>{{ request.reason }}</p>
                <code class="block break-all"
                    >SHA-256 {{ request.content_hash }}</code
                >
                <p v-if="request.cancellation_reason">
                    Cancelled: {{ request.cancellation_reason }}
                </p>
                <a
                    v-if="request.status === 'local_prepared'"
                    :href="`/documents/signatures/${request.id}/packet`"
                    class="underline"
                    >{{ t('Download provider-neutral request packet') }}</a
                >
            </li>
        </ul>
        <Pagination :links="requests.links" />
        <form class="space-y-3 rounded border p-4" @submit.prevent="cancel">
            <h2 class="font-semibold">{{ t('Cancel prepared request') }}</h2>
            <label class="block"
                >{{ t('Request ID')
                }}<input
                    v-model="cancellation.signature_id"
                    required
                    type="number"
                    min="1"
                    class="ml-3 rounded border p-2" /></label
            ><label class="block"
                >{{ t('Reason')
                }}<textarea
                    v-model="cancellation.reason"
                    required
                    maxlength="2000"
                    class="block w-full rounded border p-2"
                />
            </label>
            <p
                v-for="(error, key) in cancellation.errors"
                :key="key"
                role="alert"
                class="text-destructive"
            >
                {{ error }}
            </p>
            <Button variant="outline" :disabled="cancellation.processing">{{
                t('Cancel request')
            }}</Button>
        </form>
    </div>
</template>
