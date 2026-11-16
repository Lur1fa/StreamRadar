<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Radar, LogIn, KeyRound, ShieldAlert } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    localLogin: boolean;
    oidc: { enabled: boolean; label: string };
    setupRequired: boolean;
    noMethods: boolean;
}>();

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);
const flash = computed(() => page.props.flash as { success?: string | null; error?: string | null });

const form = useForm({
    email: '',
    password: '',
});

function submit() {
    form.post('/login', {
        onFinish: () => { form.password = ''; },
    });
}
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-zinc-950 flex items-center justify-center px-4">
        <div class="w-full max-w-sm">
            <div class="text-center mb-8">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center mx-auto mb-4">
                    <Radar class="w-7 h-7 text-white" :stroke-width="2" />
                </div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">StreamRadar</h1>
                <p class="text-sm text-gray-500 dark:text-zinc-500 mt-1">Sign in to continue</p>
            </div>

            <div class="bg-white dark:bg-zinc-900 rounded-xl p-6 shadow-sm dark:shadow-none space-y-4">
                <div v-if="flash?.success" class="text-sm text-green-600 dark:text-green-400 text-center">{{ flash.success }}</div>
                <div v-if="errors.oidc" class="text-sm text-red-500 text-center">{{ errors.oidc }}</div>

                <div v-if="noMethods" class="flex items-start gap-2 text-sm text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-500/10 rounded-lg p-3">
                    <ShieldAlert class="w-4 h-4 mt-0.5 shrink-0" />
                    <span>No sign-in method is configured. Set <code>AUTH_LOCAL_LOGIN=true</code> or configure OIDC on the server.</span>
                </div>

                <div v-if="setupRequired && oidc.enabled" class="text-xs text-gray-500 dark:text-zinc-500 text-center">
                    No accounts exist yet. Sign in with SSO, or create the first administrator with a setup token on
                    <a href="/setup" class="text-purple-600 dark:text-purple-400 hover:underline">/setup</a>.
                </div>

                <a v-if="oidc.enabled" href="/auth/oidc/redirect"
                    class="w-full py-2.5 text-sm font-medium bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-lg hover:opacity-90 transition-opacity flex items-center justify-center gap-2">
                    <KeyRound class="w-4 h-4" />
                    {{ oidc.label }}
                </a>

                <div v-if="oidc.enabled && localLogin" class="flex items-center gap-3 text-[11px] uppercase tracking-wide text-gray-400 dark:text-zinc-600">
                    <span class="flex-1 h-px bg-gray-200 dark:bg-zinc-800"></span>
                    or
                    <span class="flex-1 h-px bg-gray-200 dark:bg-zinc-800"></span>
                </div>

                <form v-if="localLogin" @submit.prevent="submit" class="space-y-4">
                    <div v-if="errors.email" class="text-sm text-red-500 text-center">{{ errors.email }}</div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">E-mail</label>
                        <input v-model="form.email" autocomplete="username" type="email" autofocus required
                            class="w-full px-3 py-2.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Password</label>
                        <input v-model="form.password" type="password" autocomplete="current-password" required
                            class="w-full px-3 py-2.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                    </div>
                    <button type="submit" :disabled="form.processing || !form.email || !form.password"
                        class="w-full py-2.5 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors flex items-center justify-center gap-2">
                        <LogIn class="w-4 h-4" />
                        Sign in
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
