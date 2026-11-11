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
    translateMenuTitle: { type: Function, default: null },
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

    if (props.current?.key && typeof props.translateMenuTitle === 'function') {
        return props.translateMenuTitle(props.current.key, props.current.title);
    }

    return props.current?.title ?? props.t(props.messages, 'directoryFallback');
});

const directoryLabel = computed(() => {
    if (!props.current?.key) {
        return props.current?.title ?? '';
    }

    if (typeof props.translateMenuTitle === 'function') {
        return props.translateMenuTitle(props.current.key, props.current.title);
    }

    return props.current.title ?? props.current.key;
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
            class="ui-overlay"
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
            class="fixed right-0 top-0 flex h-full w-[min(72vw,56rem)] min-w-[18rem] flex-col border-l border-line bg-surface shadow-[-12px_0_40px_rgba(28,25,23,0.12)] dark:shadow-[-12px_0_40px_rgba(0,0,0,0.45)]"
            :class="panelClass"
            role="dialog"
            aria-modal="true"
            :aria-label="t(messages, 'card')"
        >
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-line px-4 py-3.5">
                <div class="min-w-0">
                    <p v-if="overlay && directoryLabel" class="mb-0.5 truncate text-[11px] font-medium text-ink-muted">
                        {{ directoryLabel }}
                    </p>
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <h2 class="min-w-0 truncate text-lg font-bold tracking-tight text-ink">
                            {{ drawerTitle }}
                        </h2>
                        <span
                            v-if="isDeletedInBitrix"
                            class="ui-chip bg-red-600 text-white dark:bg-red-500"
                        >
                            {{ t(messages, 'deletedInBitrix') }}
                        </span>
                    </div>
                </div>
                <button
                    type="button"
                    class="ui-btn ui-btn-secondary shrink-0"
                    @click="closeCardModal"
                >
                    {{ t(messages, 'closeCard') }}
                </button>
            </div>
            <div class="flex shrink-0 items-center justify-between border-b border-line px-4">
                <div class="flex gap-0.5">
                    <button
                        type="button"
                        class="ui-tab"
                        :class="cardModalTab === 'form' ? 'ui-tab-active' : 'ui-tab-idle'"
                        @click="setCardModalTab('form')"
                    >
                        {{ t(messages, 'card') }}
                    </button>
                    <button
                        type="button"
                        class="ui-tab"
                        :class="cardModalTab === 'timeline' ? 'ui-tab-active' : 'ui-tab-idle'"
                        @click="setCardModalTab('timeline')"
                    >
                        {{ t(messages, 'timeline') }}
                    </button>
                </div>
                <label
                    v-if="cardModalTab === 'timeline'"
                    class="inline-flex cursor-pointer items-center gap-1.5 text-xs text-ink-muted"
                >
                    <input
                        v-model="showTechnicalFields"
                        type="checkbox"
                        class="ui-check"
                    >
                    <span>{{ t(messages, 'showTechnicalFields') }}</span>
                </label>
            </div>
            <div class="ui-scroll min-h-0 flex-1 overflow-y-auto">
                <div v-if="rowDetailLoading" class="ui-muted flex items-center justify-center py-16">
                    {{ t(messages, 'loading') }}
                </div>
                <template v-else>
                    <RecordForm
                        v-show="cardModalTab === 'form'"
                        :record="form"
                        :empty-message="t(messages, 'select')"
                        :open-linked-record="openLinkedRecord"
                    />
                    <div v-show="cardModalTab === 'timeline'" class="p-4 md:p-5">
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
