<script setup>
import { computed, ref } from 'vue';
import RecordForm from '../../../Components/RecordForm.vue';
import TimelineEventList from './TimelineEventList.vue';

const props = defineProps({
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
    openLinkedRecord: { type: Function, default: null },
    overlay: { type: Boolean, default: false },
});

const showTechnicalFields = ref(false);

const backdropClass = computed(() => (props.overlay ? 'z-[80]' : 'z-[60]'));
const panelClass = computed(() => (props.overlay ? 'z-[90]' : 'z-[70]'));

const drawerTitle = computed(() => {
    const name = String(props.form?.name ?? '').trim();
    if (name !== '') {
        return name;
    }

    const title = String(props.form?.title ?? '').trim();
    if (title !== '') {
        return title;
    }

    const firstName = String(props.form?.first_name ?? '').trim();
    const lastName = String(props.form?.last_name ?? '').trim();
    const fullName = `${firstName} ${lastName}`.trim();
    if (fullName !== '') {
        return fullName;
    }

    return props.current?.title ?? 'Directory';
});

const isDeletedInBitrix = computed(() => {
    const value = props.form?.is_deleted;

    return value === true || value === 1 || value === '1';
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
            class="fixed inset-0 bg-black/40"
            :class="backdropClass"
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
            class="fixed right-0 top-0 flex h-full w-[min(80vw,87.5rem)] min-w-[18rem] flex-col border-l border-slate-200 bg-white shadow-[-8px_0_32px_rgba(0,0,0,0.12)] dark:border-slate-700 dark:bg-slate-900 dark:shadow-[-8px_0_32px_rgba(0,0,0,0.4)]"
            :class="panelClass"
            role="dialog"
            aria-modal="true"
            :aria-label="t(messages, 'card')"
        >
            <div class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <div class="min-w-0">
                    <p v-if="overlay && current?.title" class="mb-0.5 truncate text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        {{ current.title }}
                    </p>
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <h2 class="min-w-0 truncate text-lg font-semibold text-slate-900 dark:text-slate-100 md:text-xl">
                            {{ drawerTitle }}
                        </h2>
                        <span
                            v-if="isDeletedInBitrix"
                            class="inline-flex shrink-0 items-center rounded-md bg-red-600 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-white shadow-sm dark:bg-red-500"
                        >
                            {{ t(messages, 'deletedInBitrix') }}
                        </span>
                    </div>
                </div>
                <button
                    type="button"
                    class="shrink-0 cursor-pointer rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                    @click="closeCardModal"
                >
                    {{ t(messages, 'closeCard') }}
                </button>
            </div>
            <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-4 dark:border-slate-700">
                <div class="flex gap-1">
                    <button
                        type="button"
                        class="cursor-pointer border-b-2 px-5 py-3.5 text-base font-medium transition"
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
                        class="cursor-pointer border-b-2 px-5 py-3.5 text-base font-medium transition"
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
                <label
                    v-if="cardModalTab === 'timeline'"
                    class="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-300"
                >
                    <input
                        v-model="showTechnicalFields"
                        type="checkbox"
                        class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-400 dark:border-slate-600 dark:bg-slate-800"
                    >
                    <span>Показать служебные поля</span>
                </label>
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
                        :open-linked-record="openLinkedRecord"
                    />
                    <div v-show="cardModalTab === 'timeline'" class="p-6 md:p-8 lg:p-10">
                        <TimelineEventList
                            :timeline="timeline"
                            :messages="messages"
                            :t="t"
                            :render-value="renderValue"
                            :show-technical-fields="showTechnicalFields"
                        />
                    </div>
                </template>
            </div>
        </aside>
    </Transition>
</template>
