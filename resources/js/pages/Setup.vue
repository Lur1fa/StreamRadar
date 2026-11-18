<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck, Terminal } from 'lucide-vue-next';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { usePageErrors } from '@/composables/usePageErrors';

defineProps<{ tokenActive: boolean; ttlMinutes: number }>();

const errors = usePageErrors();

const form = useForm({
    setup_token: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/setup', {
        onFinish: () => { form.password = ''; form.password_confirmation = ''; },
    });
}
</script>

<template>
    <AuthLayout title="Welcome to StreamRadar" subtitle="Create the first administrator account" wide>
            <form @submit.prevent="submit" class="bg-white dark:bg-zinc-900 rounded-xl p-6 shadow-sm dark:shadow-none space-y-4">
                <div class="flex items-start gap-2 text-xs text-gray-600 dark:text-zinc-400 bg-gray-50 dark:bg-zinc-800/60 rounded-lg p-3">
                    <Terminal class="w-4 h-4 mt-0.5 shrink-0 text-purple-500" />
                    <div>
                        <p>Paste the one-time setup token from the server. It was printed when StreamRadar started, or run:</p>
                        <code class="block mt-1 px-2 py-1 rounded bg-white dark:bg-zinc-900 text-[11px]">php artisan auth:setup-token</code>
                        <p class="mt-1">Docker: <code>docker compose logs streamradar</code> or <code>docker compose exec streamradar php artisan auth:setup-token</code></p>
                        <p v-if="!tokenActive" class="mt-2 text-amber-600 dark:text-amber-400">No active token found. Generate one with the command above (valid {{ ttlMinutes }} minutes).</p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Setup token</label>
                    <input v-model="form.setup_token" type="password" autocomplete="one-time-code" required
                        class="w-full px-3 py-2.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500 font-mono" />
                    <p v-if="errors.setup_token" class="text-xs text-red-500 mt-1">{{ errors.setup_token }}</p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Your name</label>
                    <input v-model="form.name" type="text" autocomplete="name" required
                        class="w-full px-3 py-2.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                    <p v-if="errors.name" class="text-xs text-red-500 mt-1">{{ errors.name }}</p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">E-mail (used to sign in)</label>
                    <input v-model="form.email" type="email" autocomplete="username" required
                        class="w-full px-3 py-2.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                    <p v-if="errors.email" class="text-xs text-red-500 mt-1">{{ errors.email }}</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Password</label>
                        <input v-model="form.password" type="password" autocomplete="new-password" required
                            class="w-full px-3 py-2.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Confirm password</label>
                        <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required
                            class="w-full px-3 py-2.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                    </div>
                </div>
                <p v-if="errors.password" class="text-xs text-red-500">{{ errors.password }}</p>

                <button type="submit" :disabled="form.processing"
                    class="w-full py-2.5 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors flex items-center justify-center gap-2">
                    <ShieldCheck class="w-4 h-4" />
                    Create administrator
                </button>
            </form>
    </AuthLayout>
</template>
