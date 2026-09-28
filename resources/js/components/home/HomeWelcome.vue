<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartLine, CheckCheck, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { statFigure } from '@/lib/format';
import type { Figure, HomeView } from '@/lib/home';
import { greetingKey, summarySentences } from '@/lib/home';

const props = defineProps<{ view: HomeView }>();
const page = usePage();
const { t, locale } = useLocale();
const now = new Date();
const firstName = computed(
    () => String(page.props.auth.user.name ?? '').split(' ')[0] || '',
);
const dateLine = computed(() =>
    new Intl.DateTimeFormat(
        locale.value === 'ar' ? 'ar-AE-u-nu-latn' : 'en-GB',
        { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' },
    ).format(now),
);
const sentences = computed(() => summarySentences(props.view));
const headline = computed<{ label: string; figure: Figure }[]>(() => [
    { label: 'Revenue', figure: props.view.figures.revenue },
    { label: 'Net profit', figure: props.view.figures.net_profit },
    { label: 'Cash balance', figure: props.view.figures.cash_balance },
    {
        label: 'Commission payable',
        figure: props.view.figures.commission_payable,
    },
]);

function money(figure: Figure) {
    return statFigure(
        { value: figure.value, currency: 'AED', compact: false, decimals: 0 },
        locale.value,
    );
}
</script>

<template>
    <section
        class="bg-brand-deep shadow-overlay relative grid gap-7 overflow-hidden rounded-xl p-7 lg:grid-cols-[1.4fr_1fr]"
    >
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -end-24 -top-40 size-[520px] rounded-full bg-[radial-gradient(closest-side,rgba(201,162,122,0.28),transparent)]"
        />
        <div class="relative">
            <p class="text-eyebrow !text-[#e6c9a2]">
                {{ t('Home') }} · {{ dateLine }}
            </p>
            <h1
                class="font-display mt-2 mb-3 text-4xl leading-[1.05] font-medium md:text-[44px]"
            >
                {{ t(greetingKey(now.getHours()), { name: firstName }) }}
            </h1>
            <p class="max-w-xl text-sm leading-relaxed text-[#fbf3ef]/80">
                <template v-for="(sentence, index) in sentences" :key="index">
                    <span
                        :class="
                            sentence.emphasis ? 'font-medium text-white' : ''
                        "
                        >{{ t(sentence.key, sentence.params ?? {}) }}</span
                    >{{ ' ' }}
                </template>
            </p>
            <div class="mt-5 flex flex-wrap gap-2">
                <span
                    class="inline-flex h-9 cursor-not-allowed items-center gap-2 rounded-md bg-[#e6c9a2]/60 px-4 text-sm font-medium text-[#3a1520]"
                    :title="t('Soon')"
                    ><CheckCheck class="size-4" />{{
                        t('Review approvals')
                    }}</span
                >
                <Link
                    href="/reservations"
                    class="inline-flex h-9 items-center gap-2 rounded-md border border-white/25 bg-white/10 px-4 text-sm font-medium hover:bg-white/15"
                    ><Plus class="size-4" />{{ t('New deal') }}</Link
                >
                <Link
                    href="/reports/property-profitability"
                    class="inline-flex h-9 items-center gap-2 rounded-md border border-white/25 bg-white/10 px-4 text-sm font-medium hover:bg-white/15"
                    ><ChartLine class="size-4" />{{ t('Reports') }}</Link
                >
            </div>
        </div>
        <div
            class="relative grid grid-cols-2 gap-px self-center overflow-hidden rounded-lg bg-white/15"
        >
            <div
                v-for="item in headline"
                :key="item.label"
                class="bg-[#3a1520]/55 p-4 backdrop-blur-sm"
            >
                <span
                    class="text-[10px] tracking-[0.16em] text-[#fbf3ef]/65 uppercase"
                    >{{ t(item.label) }}</span
                >
                <span
                    class="font-display mt-1.5 block text-[28px] leading-none lining-nums tabular-nums"
                >
                    <span
                        v-if="money(item.figure).prefix"
                        class="me-1.5 align-[0.7em] font-sans text-[10px] tracking-[0.12em] text-[#fbf3ef]/60"
                        >{{ money(item.figure).prefix }}</span
                    >{{ money(item.figure).figure
                    }}<span
                        v-if="money(item.figure).suffix"
                        class="ms-2 text-[0.45em]"
                        >{{ money(item.figure).suffix }}</span
                    >
                </span>
                <span class="mt-1 block text-[11px] text-[#fbf3ef]/60">
                    <template v-if="item.figure.soon">{{
                        t('Coming soon')
                    }}</template>
                    <template v-else-if="item.figure.change !== null"
                        >{{ item.figure.change > 0 ? '▲' : '▼' }}
                        {{ Math.abs(item.figure.change) }}%</template
                    >
                    <template
                        v-else-if="
                            item.label === 'Commission payable' &&
                            item.figure.count
                        "
                        >{{
                            t('across :count agents', {
                                count: item.figure.count,
                            })
                        }}</template
                    >
                </span>
            </div>
        </div>
    </section>
</template>
