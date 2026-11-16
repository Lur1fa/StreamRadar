<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { UserCircle, KeyRound } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AuthUser } from '@/types';

const props = defineProps<{ account: AuthUser; provider: string; passwordMinLength: number }>();

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.put('/account/password', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onFinish: () => { form.current_password = ''; form.password = ''; form.password_confirmation = ''; },
    });
}
</script>

<template>
    <AppLayout>
        <div class="max-w-2xl space-y-6">
            <div class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none">
                <div class="flex items-center gap-2 mb-4">
                    <UserCircle class="w-5 h-5 text-purple-500" />
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Your account</h3>
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-xs text-gray-500 dark:text-zinc-500">Name</dt><dd class="text-gray-900 dark:text-white">{{ account.name }}</dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-zinc-500">E-mail</dt><dd class="text-gray-900 dark:text-white">{{ account.email }}</dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-zinc-500">Role</dt><dd class="text-gray-900 dark:text-white capitalize">{{ account.role }}</dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-zinc-500">Signed in via</dt><dd class="text-gray-900 dark:text-white">{{ provider === 'oidc' ? 'Single sign-on' : 'Password' }}</dd></div>
                </dl>
            </div>

            <div class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none">
                <div class="flex items-center gap-2 mb-1">
                    <KeyRound class="w-5 h-5 text-purple-500" />
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Change password</h3>
                </div>
                <p v-if="!account.has_password" class="text-xs text-gray-500 dark:text-zinc-500">
                    This account signs in through single sign-on and has no local password.
                </p>
                <form v-else @submit.prevent="submit" class="space-y-4 mt-3">
                    <p class="text-xs text-gray-500 dark:text-zinc-500">Changing your password signs out all other sessions. Minimum {{ passwordMinLength }} characters.</p>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Current password</label>
                        <input v-model="form.current_password" type="password" autocomplete="current-password" required
                            class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        <p v-if="errors.current_password" class="text-xs text-red-500 mt-1">{{ errors.current_password }}</p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">New password</label>
                            <input v-model="form.password" type="password" autocomplete="new-password" required
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Confirm new password</label>
                            <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                    </div>
                    <p v-if="errors.password" class="text-xs text-red-500">{{ errors.password }}</p>
                    <button type="submit" :disabled="form.processing"
                        class="px-4 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors">
                        Update password
                    </button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
