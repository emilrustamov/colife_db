<script setup>
import RecordForm from '../../../Components/RecordForm.vue';

defineProps({
    cardModalOpen: { type: Boolean, default: false },
    menuLength: { type: Number, default: 0 },
    current: { type: Object, default: null },
    cardModalTab: { type: String, default: 'form' },
    rowDetailLoading: { type: Boolean, default: false },
    form: { type: Object, default: () => ({}) },
    timeline: { type: Array, default: () => [] },
    messages: { type: Object, required: true },
    t: { type: Function, required: true },
    closeCardModal: { type: Function, required: true },
    setCardModalTab: { type: Function, required: true },
    renderValue: { type: Function, required: true },
});
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-300 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="cardModalOpen && menuLength > 0"
            class="fixed inset-0 z-[60] bg-black/40"
            aria-hidden="true"
            @click.self="closeCardModal"
        ></div>
    </Transition>
    <Transition
        enter-active-class="transition-transform duration-300 ease-out"
        enter-from-class="translate-x-full"
        enter-to-class="translate-x-0"
        leave-active-class="transition-transform duration-300 ease-in"
        leave-from-class="translate-x-0"
        leave-to-class="translate-x-full"
    >
        <aside
            v-if="cardModalOpen && menuLength > 0"
            class="fixed right-0 top-0 z-[70] flex h-full w-[min(80vw,87.5rem)] min-w-[18rem] flex-col border-l border-slate-200 bg-white shadow-[-8px_0_32px_rgba(0,0,0,0.12)] dark:border-slate-700 dark:bg-slate-900 dark:shadow-[-8px_0_32px_rgba(0,0,0,0.4)]"
            role="dialog"
            aria-modal="true"
            :aria-label="t(messages, 'card')"
        >
            <div class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <h2 class="min-w-0 truncate text-lg font-semibold text-slate-900 dark:text-slate-100 md:text-xl">
                    {{ current?.title ?? 'Directory' }}
                </h2>
                <button
                    type="button"
                    class="shrink-0 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                    @click="closeCardModal"
                >
                    {{ t(messages, 'closeCard') }}
                </button>
            </div>
            <div class="flex shrink-0 gap-1 border-b border-slate-200 px-4 dark:border-slate-700">
                <button
                    type="button"
                    class="border-b-2 px-5 py-3.5 text-base font-medium transition"
                    :class="
                        cardModalTab === 'form'
                            ? 'border-slate-900 text-slate-900 dark:border-slate-100 dark:text-slate-100'
                            : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
                    "
                    @click="setCardModalTab('form')"
                >
                    {{ t(messages, 'card') }}
                </button>
                <button
                    type="button"
                    class="border-b-2 px-5 py-3.5 text-base font-medium transition"
                    :class="
                        cardModalTab === 'timeline'
                            ? 'border-slate-900 text-slate-900 dark:border-slate-100 dark:text-slate-100'
                            : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
                    "
                    @click="setCardModalTab('timeline')"
                >
                    {{ t(messages, 'timeline') }}
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto">
                <div v-if="rowDetailLoading" class="flex items-center justify-center py-24 text-lg text-slate-500 dark:text-slate-400">
                    {{ t(messages, 'loading') }}
                </div>
                <template v-else>
                    <RecordForm
                        v-show="cardModalTab === 'form'"
                        :record="form"
                        :empty-message="t(messages, 'select')"
                    />
                    <div v-show="cardModalTab === 'timeline'" class="p-6 md:p-8 lg:p-10">
                        <div v-if="timeline.length === 0" class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-lg text-slate-500 dark:border-slate-600 dark:text-slate-400">
                            {{ t(messages, 'noEvents') }}
                        </div>
                        <div v-else class="space-y-5">
                            <div
                                v-for="event in timeline"
                                :key="event.id"
                                class="relative rounded-xl border border-slate-200 bg-slate-50/80 p-5 pl-6 dark:border-slate-700 dark:bg-slate-800/50"
                            >
                                <span class="absolute left-2 top-6 h-2.5 w-2.5 rounded-full bg-slate-500 dark:bg-slate-300"></span>
                                <div class="mb-2 text-sm text-slate-500 dark:text-slate-400">{{ event.happened_at || event.created_at }}</div>
                                <div class="mb-3 text-lg font-semibold text-slate-900 dark:text-slate-100">{{ event.event }}</div>
                                <div class="mb-2 text-base text-slate-600 dark:text-slate-300">{{ t(messages, 'old') }}: {{ renderValue(event.old_values) }}</div>
                                <div class="text-base text-slate-600 dark:text-slate-300">{{ t(messages, 'new') }}: {{ renderValue(event.new_values) }}</div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </aside>
    </Transition>
</template>
