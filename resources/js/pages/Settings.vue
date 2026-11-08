<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { Key, RefreshCw, Filter, Mail, Palette, Terminal, X, Plus, MessageCircle, Lock, ShieldOff, Webhook, Download, Upload, Send, Loader2, BotMessageSquare, ArrowUpCircle } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { useTheme } from '@/composables/useTheme';

const props = defineProps<{ settings: Record<string, any>; activeCategoriesCount: number }>();
const { theme: currentTheme, setTheme } = useTheme();

const form = useForm({
    twitch_client_id: props.settings.twitch_client_id || '',
    twitch_client_secret: props.settings.twitch_client_secret || '',
    auto_sync_enabled: props.settings.auto_sync_enabled !== '0' && props.settings.auto_sync_enabled !== false,
    sync_frequency_minutes: props.settings.sync_frequency_minutes || '1',
    global_min_viewers: props.settings.global_min_viewers || '0',
    global_min_avg_viewers: props.settings.global_min_avg_viewers || '0',
    global_languages: props.settings.global_languages || '[]',
    global_keywords: props.settings.global_keywords || '[]',
    theme: props.settings.theme || 'system',
    mail_to: props.settings.mail_to || '',
    smtp_host: props.settings.smtp_host || 'mailpit',
    smtp_port: props.settings.smtp_port || '1025',
    smtp_username: props.settings.smtp_username || '',
    smtp_password: props.settings.smtp_password || '',
    smtp_encryption: props.settings.smtp_encryption || '',
    mail_from_address: props.settings.mail_from_address || 'streamradar@localhost',
    mail_from_name: props.settings.mail_from_name || 'StreamRadar',
    discord_webhook_url: props.settings.discord_webhook_url || '',
    telegram_bot_token: props.settings.telegram_bot_token || '',
    telegram_chat_id: props.settings.telegram_chat_id || '',
    webhook_url: props.settings.webhook_url || '',
    webhook_format: props.settings.webhook_format || 'json',
    notifications_email_enabled: props.settings.notifications_email_enabled !== '0',
    notifications_discord_enabled: props.settings.notifications_discord_enabled !== '0',
    notifications_telegram_enabled: props.settings.notifications_telegram_enabled !== '0',
    notifications_webhook_enabled: props.settings.notifications_webhook_enabled !== '0',
    auth_username: props.settings.auth_username || '',
    auth_password: '',
});

const globalLangs = ref<string[]>((() => { try { return JSON.parse(form.global_languages || '[]'); } catch { return []; } })());
const globalKws = ref<string[]>((() => { try { return JSON.parse(form.global_keywords || '[]'); } catch { return []; } })());
const langInput = ref(''); const kwInput = ref('');

const initialLangs = computed<string[]>(() => { try { return JSON.parse(props.settings.global_languages || '[]'); } catch { return []; } });
const initialKws = computed<string[]>(() => { try { return JSON.parse(props.settings.global_keywords || '[]'); } catch { return []; } });
const isFormDirty = computed(() => form.isDirty || JSON.stringify(globalLangs.value) !== JSON.stringify(initialLangs.value) || JSON.stringify(globalKws.value) !== JSON.stringify(initialKws.value));

function addLang() { const v = langInput.value.trim().toLowerCase(); if (v && !globalLangs.value.includes(v)) globalLangs.value.push(v); langInput.value = ''; }
function removeLang(l: string) { globalLangs.value = globalLangs.value.filter(x => x !== l); }
function addKw() { const v = kwInput.value.trim(); if (v && !globalKws.value.includes(v)) globalKws.value.push(v); kwInput.value = ''; }
function removeKw(k: string) { globalKws.value = globalKws.value.filter(x => x !== k); }

function saveSettings() {
    form.global_languages = JSON.stringify(globalLangs.value);
    form.global_keywords = JSON.stringify(globalKws.value);
    form.put('/settings', {
        preserveScroll: true,
        onSuccess: () => { if (form.theme !== currentTheme.value) setTheme(form.theme as any); },
    });
}

const testing = ref(false);
const testResult = ref<{ success: boolean; message: string } | null>(null);
async function testTwitch() {
    testing.value = true; testResult.value = null;
    form.global_languages = JSON.stringify(globalLangs.value);
    form.global_keywords = JSON.stringify(globalKws.value);
    form.put('/settings', {
        preserveScroll: true,
        onSuccess: async () => {
            try {
                const { data } = await window.axios.post('/settings/test-twitch');
                testResult.value = data;
            } catch (e: any) { testResult.value = { success: false, message: e.response?.data?.message || e.message || 'Request failed' }; }
            finally { testing.value = false; }
        },
        onError: () => { testResult.value = { success: false, message: 'Failed to save settings' }; testing.value = false; },
    });
}

// Test notifications
const testingEmail = ref(false);
const testEmailResult = ref<{ success: boolean; message: string } | null>(null);
const testingDiscord = ref(false);
const testDiscordResult = ref<{ success: boolean; message: string } | null>(null);
const testingTelegram = ref(false);
const testTelegramResult = ref<{ success: boolean; message: string } | null>(null);
const testingWebhook = ref(false);
const testWebhookResult = ref<{ success: boolean; message: string } | null>(null);

async function testNotification(channel: 'email' | 'discord' | 'telegram' | 'webhook') {
    const loading = { email: testingEmail, discord: testingDiscord, telegram: testingTelegram, webhook: testingWebhook }[channel];
    const result = { email: testEmailResult, discord: testDiscordResult, telegram: testTelegramResult, webhook: testWebhookResult }[channel];
    loading.value = true; result.value = null;
    try {
        const { data } = await window.axios.post(`/settings/test-${channel}`);
        result.value = data;
    } catch (e: any) {
        result.value = { success: false, message: e.response?.data?.message || e.message || 'Request failed' };
    } finally {
        loading.value = false;
    }
}

// Disable auth
const showDisableAuth = ref(false);
const disablePassword = ref('');
const disableError = ref('');
const disabling = ref(false);

async function disableAuth() {
    disableError.value = '';
    disabling.value = true;
    try {
        await window.axios.post('/settings/disable-auth', { password: disablePassword.value });
        showDisableAuth.value = false;
        disablePassword.value = '';
        router.reload();
    } catch (e: any) {
        disableError.value = e.response?.data?.message || 'Invalid password';
    } finally {
        disabling.value = false;
    }
}

// Export / Import
async function exportConfig() {
    const { data } = await window.axios.get('/settings/export');
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `streamradar-backup-${new Date().toISOString().slice(0, 10)}.json`;
    a.click();
    URL.revokeObjectURL(url);
}

const importFileInput = ref<HTMLInputElement | null>(null);
function triggerImport() { importFileInput.value?.click(); }
function handleImport(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file);
    router.post('/settings/import', formData as any, { preserveScroll: true, forceFormData: true });
}

// API usage estimation
const TWITCH_RATE_LIMIT = 800; // requests per minute
const estimatedRequests = computed(() => {
    if (!form.auto_sync_enabled) return { perMin: 0, perHour: 0, perDay: 0, pct: 0 };
    const freq = Math.max(1, parseInt(form.sync_frequency_minutes) || 5);
    const cats = props.activeCategoriesCount || 0;
    // Per sync: 1 req per category + 1 for tracked channels + 1 token (cached, ~1/hour)
    const perSync = cats + 1;
    const syncsPerHour = 60 / freq;
    const perHour = Math.round(perSync * syncsPerHour + 1); // +1 token
    const perMin = +(perSync / freq).toFixed(1);
    const perDay = perHour * 24;
    const pct = +((perMin / TWITCH_RATE_LIMIT) * 100).toFixed(1);
    return { perMin, perHour, perDay, pct };
});

// Version check
const updateAvailable = ref(false);
const localVersion = ref('');
const remoteVersion = ref('');

async function checkForUpdate() {
    try {
        const { data } = await window.axios.get('/settings/check-update');
        updateAvailable.value = data.update_available;
        localVersion.value = data.local_version || '';
        remoteVersion.value = data.remote_version || '';
    } catch {}
}

// Sidebar scroll spy
const activeSection = ref('twitch');
const sections = [
    { id: 'twitch', label: 'Twitch API', icon: Key },
    { id: 'sync', label: 'Scanning', icon: RefreshCw },
    { id: 'filters', label: 'Global Filters', icon: Filter },
    { id: 'email', label: 'Email / SMTP', icon: Mail },
    { id: 'discord', label: 'Discord', icon: MessageCircle },
    { id: 'telegram', label: 'Telegram', icon: BotMessageSquare },
    { id: 'webhook', label: 'Webhook', icon: Webhook },
    { id: 'auth', label: 'Access', icon: Lock },
    { id: 'backup', label: 'Backup', icon: Download },
    { id: 'appearance', label: 'Appearance', icon: Palette },
];

function scrollTo(id: string) {
    activeSection.value = id;
    document.getElementById(`section-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

onMounted(() => {
    checkForUpdate();
    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                activeSection.value = entry.target.id.replace('section-', '');
            }
        }
    }, { rootMargin: '-100px 0px -60% 0px' });
    sections.forEach(s => {
        const el = document.getElementById(`section-${s.id}`);
        if (el) observer.observe(el);
    });
});
</script>

<template>
    <AppLayout>
        <div class="flex gap-8">
            <!-- Sidebar -->
            <aside class="hidden lg:block w-48 shrink-0">
                <nav class="sticky top-24 space-y-1">
                    <button
                        v-for="s in sections" :key="s.id"
                        @click="scrollTo(s.id)"
                        :class="[
                            'flex items-center gap-2 w-full px-3 py-2 text-sm font-medium rounded-lg transition-colors text-left',
                            activeSection === s.id
                                ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400'
                                : 'text-gray-500 dark:text-zinc-400 hover:text-gray-700 dark:hover:text-zinc-200 hover:bg-gray-50 dark:hover:bg-zinc-800'
                        ]"
                    >
                        <component :is="s.icon" class="w-4 h-4" />
                        {{ s.label }}
                    </button>
                </nav>
            </aside>

            <!-- Content -->
            <div class="flex-1 min-w-0 max-w-3xl space-y-6">
                <!-- Update banner -->
                <div v-if="updateAvailable" class="flex items-start gap-3 p-4 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl">
                    <ArrowUpCircle class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                    <div>
                        <p class="text-sm font-medium text-amber-800 dark:text-amber-300">A new version of StreamRadar is available!</p>
                        <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                            Current: <code class="px-1 py-0.5 bg-amber-100 dark:bg-amber-500/20 rounded">{{ localVersion }}</code>
                            → Latest: <code class="px-1 py-0.5 bg-amber-100 dark:bg-amber-500/20 rounded">{{ remoteVersion }}</code>
                        </p>
                        <p class="text-xs text-amber-700 dark:text-amber-400 mt-2">To update (Docker):</p>
                        <code class="block text-xs text-amber-800 dark:text-amber-300 bg-amber-100 dark:bg-amber-500/20 rounded px-2 py-1 mt-1">git pull && docker compose up -d --build</code>
                    </div>
                </div>
                <!-- Mobile section nav -->
                <div class="lg:hidden sticky top-16 z-20 -mx-4 px-4 py-2 bg-gray-50/80 dark:bg-zinc-950/80 backdrop-blur-md">
                    <div class="flex gap-1.5 overflow-x-auto scrollbar-hide">
                        <button v-for="s in sections" :key="s.id" @click="scrollTo(s.id)"
                            :class="['flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg whitespace-nowrap transition-colors shrink-0',
                                activeSection === s.id
                                    ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400'
                                    : 'text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800']">
                            <component :is="s.icon" class="w-3.5 h-3.5" />
                            {{ s.label }}
                        </button>
                    </div>
                </div>

                <!-- Twitch API -->
                <div id="section-twitch" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center gap-2 mb-4">
                        <Key class="w-5 h-5 text-purple-500" />
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Twitch API</h3>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Client ID</label>
                            <input v-model="form.twitch_client_id" type="text" placeholder="Your Twitch Client ID"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Client Secret</label>
                            <input v-model="form.twitch_client_secret" type="password" :placeholder="settings.twitch_client_secret_masked || 'Your Twitch Client Secret'"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <div class="flex items-center gap-3">
                            <button @click="testTwitch" :disabled="testing"
                                class="px-4 py-2 text-sm font-medium bg-gray-100 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-700 disabled:opacity-50 transition-colors">
                                {{ testing ? 'Testing...' : 'Test Connection' }}
                            </button>
                            <span v-if="testResult" :class="testResult.success ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" class="text-sm">{{ testResult.message }}</span>
                        </div>
                    </div>
                </div>

                <!-- Sync -->
                <div id="section-sync" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <RefreshCw class="w-5 h-5 text-purple-500" />
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Auto Scanning</h3>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" v-model="form.auto_sync_enabled" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" />
                            <span class="text-xs font-medium text-gray-500 dark:text-zinc-400">Enabled</span>
                        </label>
                    </div>
                    <div v-if="form.auto_sync_enabled">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-medium text-gray-600 dark:text-zinc-400">Scan Frequency</label>
                            <span class="text-sm font-semibold text-purple-600 dark:text-purple-400">{{ form.sync_frequency_minutes }} min</span>
                        </div>
                        <input v-model="form.sync_frequency_minutes" type="range" min="1" max="60" step="1"
                            class="w-full h-2 rounded-full appearance-none cursor-pointer bg-gray-200 dark:bg-zinc-700 accent-purple-600
                            [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-purple-600 [&::-webkit-slider-thumb]:shadow-md [&::-webkit-slider-thumb]:hover:bg-purple-500 [&::-webkit-slider-thumb]:transition-colors
                            [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-purple-600 [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:shadow-md" />
                        <div class="flex justify-between mt-1 text-[10px] text-gray-400 dark:text-zinc-600">
                            <span>1 min</span>
                            <span>15</span>
                            <span>30</span>
                            <span>60 min</span>
                        </div>

                        <!-- API Usage Estimate -->
                        <div v-if="estimatedRequests.perMin > 0" class="mt-4 p-3 rounded-lg bg-gray-50 dark:bg-zinc-800/50">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-medium text-gray-600 dark:text-zinc-400">Twitch API Usage</span>
                                <span class="text-xs text-gray-500 dark:text-zinc-500">{{ estimatedRequests.pct }}% of limit</span>
                            </div>
                            <div class="w-full h-2 bg-gray-200 dark:bg-zinc-700 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-300"
                                    :class="estimatedRequests.pct < 25 ? 'bg-green-500' : estimatedRequests.pct < 50 ? 'bg-yellow-500' : estimatedRequests.pct < 75 ? 'bg-orange-500' : 'bg-red-500'"
                                    :style="{ width: Math.min(estimatedRequests.pct, 100) + '%' }" />
                            </div>
                            <div class="flex justify-between mt-2 text-[10px] text-gray-400 dark:text-zinc-600">
                                <span>~{{ estimatedRequests.perMin }}/min</span>
                                <span>~{{ estimatedRequests.perHour }}/hour</span>
                                <span>~{{ estimatedRequests.perDay.toLocaleString() }}/day</span>
                            </div>
                            <p class="text-[10px] text-gray-400 dark:text-zinc-600 mt-1">
                                {{ props.activeCategoriesCount }} categories &middot; Twitch limit: 800 req/min
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Global Filters -->
                <div id="section-filters" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center gap-2 mb-1">
                        <Filter class="w-5 h-5 text-purple-500" />
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Global Filters</h3>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-zinc-500 mb-4">Default filters for categories using global settings.</p>
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Minimum Viewers</label>
                                <input v-model="form.global_min_viewers" type="number" min="0"
                                    class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Min Avg Viewers <span class="font-normal text-gray-400 dark:text-zinc-600">TwitchTracker</span></label>
                                <input v-model="form.global_min_avg_viewers" type="number" min="0"
                                    class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Languages</label>
                            <div class="flex flex-wrap gap-1 mb-2" v-if="globalLangs.length">
                                <span v-for="l in globalLangs" :key="l" class="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 rounded-full">
                                    {{ l }} <button @click="removeLang(l)" class="hover:text-red-500"><X class="w-3 h-3" /></button>
                                </span>
                            </div>
                            <div class="flex gap-2">
                                <input v-model="langInput" @keydown.enter.prevent="addLang" placeholder="e.g. en"
                                    class="flex-1 px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none" />
                                <button @click="addLang" class="px-3 py-2 text-sm bg-gray-100 dark:bg-zinc-800 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-700 flex items-center gap-1"><Plus class="w-3 h-3" /> Add</button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Keywords</label>
                            <div class="flex flex-wrap gap-1 mb-2" v-if="globalKws.length">
                                <span v-for="k in globalKws" :key="k" class="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-300 rounded-full">
                                    {{ k }} <button @click="removeKw(k)" class="hover:text-red-500"><X class="w-3 h-3" /></button>
                                </span>
                            </div>
                            <div class="flex gap-2">
                                <input v-model="kwInput" @keydown.enter.prevent="addKw" placeholder="keyword"
                                    class="flex-1 px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none" />
                                <button @click="addKw" class="px-3 py-2 text-sm bg-gray-100 dark:bg-zinc-800 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-700 flex items-center gap-1"><Plus class="w-3 h-3" /> Add</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Email / SMTP -->
                <div id="section-email" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <Mail class="w-5 h-5 text-purple-500" />
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Email / SMTP</h3>
                        </div>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" v-model="form.notifications_email_enabled" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" />
                            <span class="text-xs font-medium text-gray-500 dark:text-zinc-400">Enabled</span>
                        </label>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Recipient Email</label>
                            <input v-model="form.mail_to" type="email" placeholder="you@example.com"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">SMTP Host</label>
                            <input v-model="form.smtp_host" type="text" placeholder="smtp.example.com"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">SMTP Port</label>
                            <input v-model="form.smtp_port" type="text" placeholder="587"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Encryption</label>
                            <select v-model="form.smtp_encryption"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300 outline-none focus:ring-2 focus:ring-purple-500">
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="">None</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">SMTP Username</label>
                            <input v-model="form.smtp_username" type="text"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">SMTP Password</label>
                            <input v-model="form.smtp_password" type="password"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">From Address</label>
                            <input v-model="form.mail_from_address" type="email" placeholder="noreply@example.com"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">From Name</label>
                            <input v-model="form.mail_from_name" type="text" placeholder="StreamRadar"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                    </div>
                    <div class="flex items-center gap-3 mt-4">
                        <button @click="testNotification('email')" :disabled="testingEmail"
                            class="px-4 py-2 text-sm font-medium bg-gray-100 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-700 disabled:opacity-50 transition-colors flex items-center gap-1.5">
                            <Loader2 v-if="testingEmail" class="w-3.5 h-3.5 animate-spin" />
                            <Send v-else class="w-3.5 h-3.5" />
                            {{ testingEmail ? 'Sending...' : 'Send Test Email' }}
                        </button>
                        <span v-if="testEmailResult" :class="testEmailResult.success ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" class="text-sm">{{ testEmailResult.message }}</span>
                    </div>
                </div>

                <!-- Discord -->
                <div id="section-discord" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <MessageCircle class="w-5 h-5 text-purple-500" />
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Discord</h3>
                        </div>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" v-model="form.notifications_discord_enabled" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" />
                            <span class="text-xs font-medium text-gray-500 dark:text-zinc-400">Enabled</span>
                        </label>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Webhook URL</label>
                        <input v-model="form.discord_webhook_url" autocomplete="off" type="text" placeholder="https://discord.com/api/webhooks/..."
                            class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        <p class="mt-2 text-xs text-gray-400 dark:text-zinc-500">
                            Create a webhook in your Discord server: Server Settings &rarr; Integrations &rarr; Webhooks &rarr; New Webhook. Copy the URL and paste it here.
                        </p>
                    </div>
                    <div class="flex items-center gap-3 mt-4">
                        <button @click="testNotification('discord')" :disabled="testingDiscord"
                            class="px-4 py-2 text-sm font-medium bg-gray-100 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-700 disabled:opacity-50 transition-colors flex items-center gap-1.5">
                            <Loader2 v-if="testingDiscord" class="w-3.5 h-3.5 animate-spin" />
                            <Send v-else class="w-3.5 h-3.5" />
                            {{ testingDiscord ? 'Sending...' : 'Send Test Message' }}
                        </button>
                        <span v-if="testDiscordResult" :class="testDiscordResult.success ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" class="text-sm">{{ testDiscordResult.message }}</span>
                    </div>
                </div>

                <!-- Telegram -->
                <div id="section-telegram" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <BotMessageSquare class="w-5 h-5 text-purple-500" />
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Telegram</h3>
                        </div>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" v-model="form.notifications_telegram_enabled" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" />
                            <span class="text-xs font-medium text-gray-500 dark:text-zinc-400">Enabled</span>
                        </label>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Bot Token</label>
                            <input v-model="form.telegram_bot_token" type="password" :placeholder="settings.telegram_bot_token_masked || 'Paste token from @BotFather'"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Chat ID</label>
                            <input v-model="form.telegram_chat_id" autocomplete="off" type="text" placeholder="Your numeric chat ID"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <p class="text-xs text-gray-400 dark:text-zinc-500">
                            Create a bot via <strong>@BotFather</strong> on Telegram, then send /start to your bot and enter the chat ID here.
                        </p>
                    </div>
                    <div class="flex items-center gap-3 mt-4">
                        <button @click="testNotification('telegram')" :disabled="testingTelegram"
                            class="px-4 py-2 text-sm font-medium bg-gray-100 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-700 disabled:opacity-50 transition-colors flex items-center gap-1.5">
                            <Loader2 v-if="testingTelegram" class="w-3.5 h-3.5 animate-spin" />
                            <Send v-else class="w-3.5 h-3.5" />
                            {{ testingTelegram ? 'Sending...' : 'Send Test Message' }}
                        </button>
                        <span v-if="testTelegramResult" :class="testTelegramResult.success ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" class="text-sm">{{ testTelegramResult.message }}</span>
                    </div>
                </div>

                <!-- Webhook -->
                <div id="section-webhook" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <Webhook class="w-5 h-5 text-purple-500" />
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Webhook</h3>
                        </div>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" v-model="form.notifications_webhook_enabled" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" />
                            <span class="text-xs font-medium text-gray-500 dark:text-zinc-400">Enabled</span>
                        </label>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Webhook URL</label>
                        <input v-model="form.webhook_url" autocomplete="off" type="text" placeholder="https://ntfy.sh/your-topic or any URL..."
                            class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        <p class="mt-2 text-xs text-gray-400 dark:text-zinc-500">
                            Receives a POST for every alert. Choose the payload format below: JSON works with Zapier, Make or any custom endpoint, ntfy sends a readable notification.
                        </p>
                    </div>
                    <div class="mt-3">
                        <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Payload format</label>
                        <select v-model="form.webhook_format"
                            class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500">
                            <option value="json">JSON (Zapier, Make, custom endpoints)</option>
                            <option value="ntfy">ntfy (plain text with title, click action and image)</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-3 mt-4">
                        <button @click="testNotification('webhook')" :disabled="testingWebhook"
                            class="px-4 py-2 text-sm font-medium bg-gray-100 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-700 disabled:opacity-50 transition-colors flex items-center gap-1.5">
                            <Loader2 v-if="testingWebhook" class="w-3.5 h-3.5 animate-spin" />
                            <Send v-else class="w-3.5 h-3.5" />
                            {{ testingWebhook ? 'Sending...' : 'Send Test Webhook' }}
                        </button>
                        <span v-if="testWebhookResult" :class="testWebhookResult.success ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" class="text-sm">{{ testWebhookResult.message }}</span>
                    </div>
                </div>

                <!-- Access / Auth -->
                <div id="section-auth" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center gap-2 mb-4">
                        <Lock class="w-5 h-5 text-purple-500" />
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Access Control</h3>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-zinc-500 mb-4">
                        {{ settings.auth_enabled ? 'Authentication is enabled. Leave password empty to keep current.' : 'No password set — app is open. Set credentials to require login.' }}
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Username</label>
                            <input v-model="form.auth_username" autocomplete="off" type="text" placeholder="admin"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Password</label>
                            <input v-model="form.auth_password" type="password" :placeholder="settings.auth_enabled ? '••••••••' : 'Set password to enable'"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                        </div>
                    </div>
                    <!-- Disable auth -->
                    <div v-if="settings.auth_enabled" class="mt-4">
                        <button v-if="!showDisableAuth" @click="showDisableAuth = true"
                            class="px-4 py-2 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition-colors flex items-center gap-1.5">
                            <ShieldOff class="w-4 h-4" /> Disable Access Control
                        </button>
                        <div v-else class="flex items-center gap-2 mt-2">
                            <input v-model="disablePassword" type="password" placeholder="Confirm current password"
                                class="flex-1 max-w-xs px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-red-500"
                                @keydown.enter="disableAuth" />
                            <button @click="disableAuth" :disabled="disabling || !disablePassword"
                                class="px-4 py-2 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50 transition-colors">
                                Confirm
                            </button>
                            <button @click="showDisableAuth = false; disablePassword = ''; disableError = ''"
                                class="px-3 py-2 text-sm text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800 rounded-lg transition-colors">
                                Cancel
                            </button>
                        </div>
                        <p v-if="disableError" class="text-xs text-red-500 mt-2">{{ disableError }}</p>
                    </div>
                </div>

                <!-- Backup -->
                <div id="section-backup" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center gap-2 mb-4">
                        <Download class="w-5 h-5 text-purple-500" />
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Backup</h3>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-zinc-500 mb-4">Export or import all settings, tracked categories, channels, and blacklist rules.</p>
                    <div class="flex gap-3">
                        <button @click="exportConfig"
                            class="px-4 py-2 text-sm font-medium bg-gray-100 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-700 transition-colors flex items-center gap-1.5">
                            <Download class="w-4 h-4" /> Export
                        </button>
                        <button @click="triggerImport"
                            class="px-4 py-2 text-sm font-medium bg-gray-100 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-700 transition-colors flex items-center gap-1.5">
                            <Upload class="w-4 h-4" /> Import
                        </button>
                        <input ref="importFileInput" type="file" accept=".json" class="hidden" @change="handleImport" />
                    </div>
                </div>

                <!-- Appearance -->
                <div id="section-appearance" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center gap-2 mb-4">
                        <Palette class="w-5 h-5 text-purple-500" />
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Appearance</h3>
                    </div>
                    <div class="flex gap-3">
                        <button v-for="opt in [{ value: 'light', label: 'Light' }, { value: 'dark', label: 'Dark' }, { value: 'system', label: 'System' }]"
                            :key="opt.value" @click="form.theme = opt.value"
                            :class="['px-4 py-2 text-sm font-medium rounded-lg transition-colors',
                                form.theme === opt.value
                                    ? 'bg-purple-50 text-purple-700 dark:bg-purple-500/10 dark:text-purple-300'
                                    : 'bg-gray-50 dark:bg-zinc-800 text-gray-600 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-700']">
                            {{ opt.label }}
                        </button>
                    </div>
                </div>

                <!-- Spacer for sticky bar -->
                <div v-if="isFormDirty" class="h-20"></div>
            </div>
        </div>

        <!-- Sticky Save Bar -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="translate-y-full opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="translate-y-full opacity-0"
        >
            <div v-if="isFormDirty" class="fixed bottom-0 left-0 right-0 z-30 bg-white/80 dark:bg-zinc-900/80 backdrop-blur-md py-3 px-4">
                <div class="max-w-7xl mx-auto flex justify-end">
                    <button @click="saveSettings" :disabled="form.processing"
                        class="px-6 py-2.5 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors shadow-lg">
                        {{ form.processing ? 'Saving...' : 'Save Settings' }}
                    </button>
                </div>
            </div>
        </Transition>
    </AppLayout>
</template>
