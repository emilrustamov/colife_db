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

        <div class="flex min-h-screen items-center justify-center bg-slate-100 p-4 dark:bg-slate-950">
            <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <h1 class="mb-1 text-2xl font-semibold text-slate-900 dark:text-slate-100">Вход</h1>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">Доступ к справочникам по ролям.</p>

                <form class="space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Email</label>
                        <input
                            v-model="form.email"
                            type="email"
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none ring-blue-500 focus:ring-2 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                            autocomplete="email"
                            required
                        >
                        <div v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Пароль</label>
                        <input
                            v-model="form.password"
                            type="password"
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none ring-blue-500 focus:ring-2 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                            autocomplete="current-password"
                            required
                        >
                        <div v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</div>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input v-model="form.remember" type="checkbox" class="h-4 w-4 rounded border-slate-300 dark:border-slate-600">
                        Запомнить меня
                    </label>

                    <button
                        type="submit"
                        class="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-60 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Входим...' : 'Войти' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
