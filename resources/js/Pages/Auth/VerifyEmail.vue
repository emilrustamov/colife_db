<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    email: { type: String, required: true },
    ttlMinutes: { type: Number, default: 15 },
});

const page = usePage();

const form = useForm({
    code: '',
});

const resendForm = useForm({});

const submit = () => {
    form.post('/email/verify');
};

const resend = () => {
    resendForm.post('/email/verify/resend');
};

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div>
        <Head title="Подтверждение email" />

        <div class="ui-shell flex min-h-screen items-center justify-center p-4">
            <div class="ui-panel ui-enter relative w-full max-w-md rounded-2xl p-7 shadow-lg">
                <img
                    src="/images/colife-logo.png"
                    alt="COLIFE"
                    class="ui-logo mb-6 !h-10 !max-w-none"
                >
                <h1 class="ui-section-title mb-1 !text-2xl">Подтвердите email</h1>
                <p class="ui-muted mb-6">
                    Код отправлен на <span class="text-ink">{{ email }}</span>. Действует {{ ttlMinutes }} мин.
                </p>

                <div
                    v-if="page.props.flash?.status"
                    class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-100"
                >
                    {{ page.props.flash.status }}
                </div>

                <form class="space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink">Код из письма</label>
                        <input
                            v-model="form.code"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            class="ui-input tracking-[0.28em]"
                            autocomplete="one-time-code"
                            required
                        >
                        <div v-if="form.errors.code" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.code }}</div>
                    </div>

                    <button
                        type="submit"
                        class="ui-btn ui-btn-primary w-full !py-2.5 !text-sm"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Проверяем...' : 'Подтвердить' }}
                    </button>
                </form>

                <div class="mt-4 flex items-center justify-between gap-3 text-sm">
                    <button
                        type="button"
                        class="text-ink underline-offset-2 hover:underline disabled:opacity-50"
                        :disabled="resendForm.processing"
                        @click="resend"
                    >
                        {{ resendForm.processing ? 'Отправляем...' : 'Отправить код ещё раз' }}
                    </button>
                    <button
                        type="button"
                        class="text-ink-muted underline-offset-2 hover:underline"
                        @click="logout"
                    >
                        Выйти
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
