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
    if (value === null || value === undefined) {
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
</script>

<template>
    <div class="p-6 md:p-8 lg:p-10">
        <div
            v-if="visibleFields.length === 0"
            class="rounded-xl border border-dashed border-slate-300 bg-slate-50/80 p-10 text-center text-lg text-slate-500 dark:border-slate-600 dark:bg-slate-800/40 dark:text-slate-400"
        >
            {{ emptyMessage }}
        </div>
        <div
            v-else
            class="grid grid-cols-1 gap-7 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div v-for="key in visibleFields" :key="key" class="min-w-0">
                <label class="mb-2 block text-sm font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">{{ key }}</label>
                <button
                    v-if="fieldHref(key)"
                    type="button"
                    class="w-full cursor-pointer rounded-xl border border-sky-300 bg-sky-50 px-4 py-3.5 text-left text-lg leading-relaxed text-sky-800 shadow-sm transition hover:border-sky-400 hover:bg-sky-100 dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-200 dark:hover:bg-sky-950/70"
                    @click="onOpenLinked(fieldHref(key))"
                >
                    {{ formatValue(record[key], key, record) }}
                </button>
                <input
                    v-else
                    :value="formatValue(record[key], key, record)"
                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3.5 text-lg leading-relaxed text-slate-800 shadow-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                    readonly
                >
            </div>
        </div>
    </div>
</template>
