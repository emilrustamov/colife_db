<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
});

const submit = () => {
    form.post('/forgot-password');
};
</script>

<template>
    <div>
        <Head title="Сброс пароля" />

        <div class="ui-shell flex min-h-screen items-center justify-center p-4">
            <div class="ui-panel ui-enter relative w-full max-w-md rounded-2xl p-7 shadow-lg">
                <img
                    src="/images/colife-logo.png"
                    alt="COLIFE"
                    class="ui-logo mb-6 !h-10 !max-w-none"
                >
                <h1 class="ui-section-title mb-1 !text-2xl">Сброс пароля</h1>
                <p class="ui-muted mb-6">Отправим 6-значный код на ваш email.</p>

                <form class="space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink">Email</label>
                        <input
                            v-model="form.email"
                            type="email"
                            class="ui-input"
                            autocomplete="email"
                            required
                        >
                        <div v-if="form.errors.email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.email }}</div>
                    </div>

                    <button
                        type="submit"
                        class="ui-btn ui-btn-primary w-full !py-2.5 !text-sm"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Отправляем...' : 'Отправить код' }}
                    </button>

                    <p class="text-center text-sm text-ink-muted">
                        <Link href="/login" class="underline-offset-2 hover:underline">Назад ко входу</Link>
                    </p>
                </form>
            </div>
        </div>
    </div>
</template>
