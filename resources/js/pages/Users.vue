<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Users as UsersIcon, Plus, Trash2, ShieldCheck, Ban, KeyRound, X } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import { useAuth } from '@/composables/useAuth';
import type { AuthUser, Role } from '@/types';

const props = defineProps<{ users: AuthUser[]; roles: Role[]; passwordMinLength: number }>();

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);
const { user: me } = useAuth();

const showForm = ref(false);
const form = useForm({ name: '', email: '', role: 'user' as Role, password: '' });

function submit() {
    form.post('/users', { preserveScroll: true, onSuccess: () => { form.reset(); showForm.value = false; } });
}

function setRole(u: AuthUser, role: Role) {
    if (u.role === role) return;
    router.put(`/users/${u.id}`, { role }, { preserveScroll: true });
}

function toggleDisabled(u: AuthUser) {
    router.put(`/users/${u.id}`, { disabled: !u.disabled }, { preserveScroll: true });
}

const resettingId = ref<number | null>(null);
const resetForm = useForm({ password: '' });
function submitReset(u: AuthUser) {
    resetForm.put(`/users/${u.id}`, { preserveScroll: true, onSuccess: () => { resetForm.reset(); resettingId.value = null; } });
}

const deleting = ref<AuthUser | null>(null);
function confirmDelete() {
    if (!deleting.value) return;
    router.delete(`/users/${deleting.value.id}`, { preserveScroll: true, onSuccess: () => { deleting.value = null; } });
}

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : 'never';
}
</script>

<template>
    <AppLayout>
        <div class="max-w-4xl">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-2">
                    <UsersIcon class="w-5 h-5 text-purple-500" />
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">Users</h2>
                    <span class="text-xs text-gray-400 dark:text-zinc-600">{{ users.length }}</span>
                </div>
                <button @click="showForm = !showForm"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                    <Plus v-if="!showForm" class="w-4 h-4" /><X v-else class="w-4 h-4" />
                    {{ showForm ? 'Cancel' : 'New user' }}
                </button>
            </div>

            <form v-if="showForm" @submit.prevent="submit" class="mb-6 bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Name</label>
                        <input v-model="form.name" type="text" required class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        <p v-if="errors.name" class="text-xs text-red-500 mt-1">{{ errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">E-mail</label>
                        <input v-model="form.email" type="email" required autocomplete="off" class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        <p v-if="errors.email" class="text-xs text-red-500 mt-1">{{ errors.email }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Role</label>
                        <select v-model="form.role" class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500">
                            <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Initial password (min. {{ passwordMinLength }})</label>
                        <input v-model="form.password" type="password" required autocomplete="new-password" class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        <p v-if="errors.password" class="text-xs text-red-500 mt-1">{{ errors.password }}</p>
                    </div>
                </div>
                <p class="text-xs text-gray-500 dark:text-zinc-500"><strong>admin</strong> can change settings, users and tracking; <strong>user</strong> can only view.</p>
                <button type="submit" :disabled="form.processing" class="px-4 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors">Create user</button>
            </form>

            <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm dark:shadow-none divide-y divide-gray-100 dark:divide-zinc-800">
                <div v-for="u in users" :key="u.id" class="px-5 py-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-gray-900 dark:text-white truncate">{{ u.name }}</span>
                                <span v-if="u.id === me?.id" class="text-[10px] px-1.5 py-0.5 rounded-full bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-300">you</span>
                                <span v-if="u.sso" class="text-[10px] px-1.5 py-0.5 rounded-full bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300">SSO</span>
                                <span v-if="u.disabled" class="text-[10px] px-1.5 py-0.5 rounded-full bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-300">disabled</span>
                            </div>
                            <div class="text-xs text-gray-500 dark:text-zinc-500 truncate">{{ u.email }} · last login {{ formatDate(u.last_login_at) }}</div>
                        </div>

                        <select :value="u.role" @change="setRole(u, ($event.target as HTMLSelectElement).value as Role)" :disabled="u.id === me?.id"
                            class="px-2 py-1 text-xs rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300 outline-none disabled:opacity-50">
                            <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
                        </select>

                        <button v-if="u.has_password" @click="resettingId = resettingId === u.id ? null : u.id" class="p-1.5 text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800" v-tooltip="'Set new password'">
                            <KeyRound class="w-4 h-4" />
                        </button>
                        <button @click="toggleDisabled(u)" :disabled="u.id === me?.id" class="p-1.5 text-gray-400 hover:text-amber-600 dark:hover:text-amber-400 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 disabled:opacity-30" v-tooltip="u.disabled ? 'Enable account' : 'Disable account'">
                            <ShieldCheck v-if="u.disabled" class="w-4 h-4" /><Ban v-else class="w-4 h-4" />
                        </button>
                        <button @click="deleting = u" :disabled="u.id === me?.id" class="p-1.5 text-gray-400 hover:text-red-600 dark:hover:text-red-400 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 disabled:opacity-30" v-tooltip="'Delete'">
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>

                    <form v-if="resettingId === u.id" @submit.prevent="submitReset(u)" class="mt-3 flex flex-wrap items-center gap-2">
                        <input v-model="resetForm.password" type="password" autocomplete="new-password" :placeholder="`New password (min. ${passwordMinLength})`" required
                            class="flex-1 min-w-[12rem] px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        <button type="submit" :disabled="resetForm.processing" class="px-3 py-1.5 text-xs font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">Set password</button>
                        <button type="button" @click="resettingId = null; resetForm.reset()" class="px-3 py-1.5 text-xs text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800 rounded-lg">Cancel</button>
                        <p v-if="errors.password" class="w-full text-xs text-red-500">{{ errors.password }}</p>
                    </form>
                </div>
            </div>

            <p class="mt-4 text-xs text-gray-400 dark:text-zinc-600">
                Accounts can also be managed from the command line: <code>php artisan user:create --admin</code>, <code>user:password</code>, <code>user:role</code>, <code>user:list</code>.
            </p>
        </div>

        <ConfirmModal :show="!!deleting" title="Delete user?" :message="`${deleting?.name} will no longer be able to sign in.`" confirm-label="Delete" @confirm="confirmDelete" @cancel="deleting = null" />
    </AppLayout>
</template>
