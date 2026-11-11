<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login');
};
</script>

<template>
    <div>
        <Head title="Login" />

        <div class="ui-shell flex min-h-screen items-center justify-center p-4">
            <div class="ui-panel ui-enter relative w-full max-w-md rounded-2xl p-7 shadow-lg">
                <img
                    src="/images/colife-logo.png"
                    alt="COLIFE"
                    class="ui-logo mb-6 !h-10 !max-w-none"
                >
                <h1 class="ui-section-title mb-1 !text-2xl">Вход</h1>
                <p class="ui-muted mb-6">Доступ к меню по ролям.</p>

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

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink">Пароль</label>
                        <input
                            v-model="form.password"
                            type="password"
                            class="ui-input"
                            autocomplete="current-password"
                            required
                        >
                        <div v-if="form.errors.password" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.password }}</div>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-ink-muted">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            class="ui-check"
                        >
                        Запомнить меня
                    </label>

                    <button
                        type="submit"
                        class="ui-btn ui-btn-primary w-full !py-2.5 !text-sm"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Входим...' : 'Войти' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
