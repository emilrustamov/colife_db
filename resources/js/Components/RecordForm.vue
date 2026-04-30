<script setup>
defineProps({
    record: {
        type: Object,
        default: () => ({}),
    },
    emptyMessage: {
        type: String,
        default: '',
    },
});

const formatValue = (value) => {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value, null, 0);
    }

    return String(value);
};
</script>

<template>
    <div class="p-6 md:p-8 lg:p-10">
        <div
            v-if="Object.keys(record).length === 0"
            class="rounded-xl border border-dashed border-slate-300 bg-slate-50/80 p-10 text-center text-lg text-slate-500 dark:border-slate-600 dark:bg-slate-800/40 dark:text-slate-400"
        >
            {{ emptyMessage }}
        </div>
        <div
            v-else
            class="grid grid-cols-1 gap-7 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div v-for="(value, key) in record" :key="key" class="min-w-0">
                <label class="mb-2 block text-sm font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">{{ key }}</label>
                <input
                    :value="formatValue(value)"
                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3.5 text-lg leading-relaxed text-slate-800 shadow-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                    readonly
                >
            </div>
        </div>
    </div>
</template>
