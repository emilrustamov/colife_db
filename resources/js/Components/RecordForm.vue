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
            class="rounded-lg border border-dashed border-slate-300 bg-slate-50/60 px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-600 dark:bg-slate-800/40 dark:text-slate-400"
        >
            {{ emptyMessage }}
        </div>
        <div
            v-else
            class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 xl:grid-cols-3"
        >
            <div v-for="key in visibleFields" :key="key" class="min-w-0">
                <label class="mb-1 block truncate text-[11px] font-medium text-slate-500 dark:text-slate-400">{{ key }}</label>
                <button
                    v-if="fieldHref(key)"
                    type="button"
                    class="w-full cursor-pointer break-words rounded-md border border-sky-200 bg-sky-50/80 px-2.5 py-1.5 text-left text-sm leading-snug text-sky-800 transition hover:border-sky-300 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200 dark:hover:bg-sky-950/70"
                    @click="onOpenLinked(fieldHref(key))"
                >
                    {{ displayValue(key) || '—' }}
                </button>
                <div
                    v-else
                    class="break-words rounded-md border border-slate-200/80 bg-slate-50 px-2.5 py-1.5 text-sm leading-snug text-slate-800 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-100"
                    :class="displayValue(key) ? '' : 'text-slate-400 dark:text-slate-500'"
                >
                    {{ displayValue(key) || '—' }}
                </div>
            </div>
        </div>
    </div>
</template>
