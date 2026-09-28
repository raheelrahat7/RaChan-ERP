<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { fieldDescription } from '@/lib/form-field';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        optional?: boolean;
        help?: string;
        error?: string;
        full?: boolean;
    }>(),
    { optional: false, help: undefined, error: undefined, full: false },
);

const { t } = useLocale();
const description = computed(() =>
    fieldDescription(props.id, { help: props.help, error: props.error }),
);
</script>

<template>
    <div :class="cn('flex flex-col gap-1.5', full && 'sm:col-span-2')">
        <Label :for="id" class="text-xs font-medium">
            {{ t(label) }}
            <span v-if="optional" class="text-muted-foreground font-normal"
                >({{ t('optional') }})</span
            >
        </Label>
        <slot
            :id="id"
            :described-by="description.describedBy"
            :invalid="description.invalid"
        />
        <p
            v-if="help && !error"
            :id="`${id}-help`"
            class="text-muted-foreground text-xs"
        >
            {{ t(help) }}
        </p>
        <InputError :id="`${id}-error`" :message="error" />
    </div>
</template>
