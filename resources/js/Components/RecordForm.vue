<script setup>
import { computed } from 'vue';

const props = defineProps({
    record: {
        type: Object,
        default: () => ({}),
    },
    emptyMessage: {
        type: String,
        default: '',
    },
    openLinkedRecord: {
        type: Function,
        default: null,
    },
});

const visibleFields = computed(() => {
    return Object.keys(props.record).filter((key) => !key.endsWith('_href'));
});

const formatValue = (value, key, record) => {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    if (key === 'contact_type_id') {
        const contactTypeId = String(value).trim();
        const contactTypeName = String(record?.contact_type_name ?? '').trim();

        if (contactTypeId !== '' && contactTypeName !== '') {
            return `${contactTypeName} (${contactTypeId})`;
        }
    }

    if (typeof value === 'object') {
        return JSON.stringify(value, null, 0);
    }

    return String(value);
};

const fieldHref = (key) => {
    const href = props.record?.[`${key}_href`];

    return typeof href === 'string' && href.trim() !== '' ? href.trim() : null;
};

const onOpenLinked = (href) => {
    if (typeof props.openLinkedRecord === 'function') {
        props.openLinkedRecord(href);
    }
};

const displayValue = (key) => formatValue(props.record[key], key, props.record);
</script>

<template>
    <div class="p-4 md:p-5">
        <div
            v-if="visibleFields.length === 0"
            class="ui-empty"
        >
            {{ emptyMessage }}
        </div>
        <div
            v-else
            class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <div v-for="key in visibleFields" :key="key" class="min-w-0">
                <label class="mb-1.5 block truncate text-[11px] font-semibold uppercase tracking-[0.08em] text-ink-muted">{{ key }}</label>
                <button
                    v-if="fieldHref(key)"
                    type="button"
                    class="w-full cursor-pointer break-words rounded-xl border border-brand-muted bg-brand-soft px-3 py-2 text-left text-sm leading-relaxed text-brand-hover transition hover:border-brand"
                    @click="onOpenLinked(fieldHref(key))"
                >
                    {{ displayValue(key) || '—' }}
                </button>
                <div
                    v-else
                    class="break-words rounded-xl border border-line bg-surface-muted/80 px-3 py-2 text-sm leading-relaxed text-ink"
                    :class="displayValue(key) ? '' : 'text-ink-muted'"
                >
                    {{ displayValue(key) || '—' }}
                </div>
            </div>
        </div>
    </div>
</template>
