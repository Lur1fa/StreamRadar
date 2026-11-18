<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useForm, router, Link } from '@inertiajs/vue3';
import { Key, RefreshCw, Filter, Mail, Palette, X, Plus, Bell, Lock, Download, Upload, ArrowUpCircle } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import NotificationDestinations from '@/components/NotificationDestinations.vue';
import FieldError from '@/components/FieldError.vue';
import SecretHint from '@/components/SecretHint.vue';
import { useTheme } from '@/composables/useTheme';
import { useAuth } from '@/composables/useAuth';
import { useChipInput } from '@/composables/useChipInput';
import { parseJson, requestErrorMessage } from '@/utils';
import type { DestinationTypeInfo, NotificationDestination } from '@/types';

const props = defineProps<{
    settings: Record<string, any>;
    destinations: NotificationDestination[];
    destinationTypes: DestinationTypeInfo[];
    activeCategoriesCount: number;
    webhookAllowedHosts: string[];
    emailAvailable: boolean;
    /** Installation settings: administrators only (Setting::SERVER_KEYS). */
    serverKeys: string[];
}>();
const { theme: currentTheme, setTheme } = useTheme();
const { isAdmin } = useAuth();

// Settings of the installation (Twitch keys, scanning, SMTP server) are shared
// by all accounts and only an administrator sees or sends them. Everything
// else on this page belongs to the signed-in account. Alert destinations are
// saved on their own (NotificationDestinations), not with this form.
// The server sends every value with its default already applied, so the
// form shows exactly what the application uses.
const value = (key: string): string => (props.settings[key] ?? '').toString();

const form = useForm({
    twitch_client_id: value('twitch_client_id'),
    twitch_client_secret: '',
    auto_sync_enabled: props.settings.auto_sync_enabled !== '0' && props.settings.auto_sync_enabled !== false,
    sync_frequency_minutes: value('sync_frequency_minutes'),
    global_min_viewers: value('global_min_viewers'),
    global_min_avg_viewers: value('global_min_avg_viewers'),
    global_languages: value('global_languages'),
    global_keywords: value('global_keywords'),
    theme: value('theme'),
    smtp_host: value('smtp_host'),
    smtp_port: value('smtp_port'),
    smtp_username: value('smtp_username'),
    smtp_password: '',
    smtp_encryption: value('smtp_encryption'),
    mail_from_address: value('mail_from_address'),
    mail_from_name: value('mail_from_name'),
    clear_secrets: [] as string[],
});

form.transform((data) => isAdmin.value
    ? data
    : Object.fromEntries(Object.entries(data).filter(([key]) => !props.serverKeys.includes(key))) as typeof data);

// Secrets are never sent to the browser. The server only tells us whether a
// value is stored (`<key>_set`) and a mask (`<key>_masked`). Leaving a secret
// field empty keeps the stored value; "remove" clears it on save.
type SecretKey = string;
function secretSet(key: SecretKey): boolean { return !!props.settings[`${key}_set`] && !form.clear_secrets.includes(key); }
function secretMask(key: SecretKey): string { return props.settings[`${key}_masked`] || ''; }
function clearSecret(key: SecretKey) { if (!form.clear_secrets.includes(key)) form.clear_secrets.push(key); (form as any)[key] = ''; }
function undoClear(key: SecretKey) { form.clear_secrets = form.clear_secrets.filter(k => k !== key); }


// Languages and keywords are stored as JSON strings and edited as chips.
const globalLangs = ref<string[]>(parseJson(form.global_languages, []));
const globalKws = ref<string[]>(parseJson(form.global_keywords, []));
const { input: langInput, add: addLang, remove: removeLang } = useChipInput(() => globalLangs.value, v => (globalLangs.value = v), { lowercase: true });
const { input: kwInput, add: addKw, remove: removeKw } = useChipInput(() => globalKws.value, v => (globalKws.value = v));

const initialLangs = computed<string[]>(() => parseJson(props.settings.global_languages, []));
const initialKws = computed<string[]>(() => parseJson(props.settings.global_keywords, []));
const isFormDirty = computed(() => form.isDirty || JSON.stringify(globalLangs.value) !== JSON.stringify(initialLangs.value) || JSON.stringify(globalKws.value) !== JSON.stringify(initialKws.value));

/** Put the chip lists back into the form before it is sent. */
function syncLists() {
    form.global_languages = JSON.stringify(globalLangs.value);
    form.global_keywords = JSON.stringify(globalKws.value);
}

// Validation errors come back per field. They are shown under the field and
// summarised in the save bar, so a rejected value is never silently dropped.
const FIELD_LABELS: Record<string, string> = {
    twitch_client_id: 'Twitch Client ID', twitch_client_secret: 'Twitch Client Secret',
    auto_sync_enabled: 'Auto scanning', sync_frequency_minutes: 'Scan frequency',
    global_min_viewers: 'Min viewers', global_min_avg_viewers: 'Min avg viewers',
    global_languages: 'Languages', global_keywords: 'Keywords', theme: 'Theme',
    smtp_host: 'SMTP host', smtp_port: 'SMTP port',
    smtp_username: 'SMTP username', smtp_password: 'SMTP password', smtp_encryption: 'SMTP encryption',
    mail_from_address: 'From address', mail_from_name: 'From name',
};
const errorList = computed(() => Object.entries(form.errors as Record<string, string>)
    .map(([key, message]) => ({ key, label: FIELD_LABELS[key] ?? key, message })));

function scrollToFirstError() {
    const first = errorList.value[0];
    if (!first) return;
    const el = document.querySelector(`[data-field="${first.key}"]`) as HTMLElement | null;
    el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el?.focus({ preventScroll: true });
}

function saveSettings() {
    syncLists();
    form.put('/settings', {
        preserveScroll: true,
        onSuccess: () => { if (form.theme !== currentTheme.value) setTheme(form.theme as any); },
        onError: () => { scrollToFirstError(); },
    });
}

const testing = ref(false);
const testResult = ref<{ success: boolean; message: string } | null>(null);
async function testTwitch() {
    testing.value = true; testResult.value = null;
    syncLists();
    form.put('/settings', {
        preserveScroll: true,
        onSuccess: async () => {
            try {
                const { data } = await window.axios.post('/settings/test-twitch');
                testResult.value = data;
            } catch (e) { testResult.value = { success: false, message: requestErrorMessage(e) }; }
            finally { testing.value = false; }
        },
        onError: () => { testResult.value = { success: false, message: 'Failed to save settings' }; testing.value = false; },
    });
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
const activeSection = ref(isAdmin.value ? 'twitch' : 'filters');
const sections = [
    { id: 'twitch', label: 'Twitch API', icon: Key, adminOnly: true },
    { id: 'sync', label: 'Scanning', icon: RefreshCw, adminOnly: true },
    { id: 'filters', label: 'Global Filters', icon: Filter },
    { id: 'notifications', label: 'Notifications', icon: Bell },
    { id: 'mail', label: 'Mail Server', icon: Mail, adminOnly: true },
    { id: 'auth', label: 'Access', icon: Lock, adminOnly: true },
    { id: 'backup', label: 'Backup', icon: Download, adminOnly: true },
    { id: 'appearance', label: 'Appearance', icon: Palette },
].filter((section) => !section.adminOnly || isAdmin.value);

function scrollTo(id: string) {
    activeSection.value = id;
    document.getElementById(`section-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

onMounted(() => {
    if (isAdmin.value) checkForUpdate();
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
                                ? 'accent-soft'
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
                                    ? 'accent-soft'
                                    : 'text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800']">
                            <component :is="s.icon" class="w-3.5 h-3.5" />
                            {{ s.label }}
                        </button>
                    </div>
                </div>

                <!-- Twitch API (installation) -->
                <div v-if="isAdmin" id="section-twitch" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center gap-2 mb-4">
                        <Key class="w-5 h-5 text-purple-500" />
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Twitch API</h3>
                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400" v-tooltip="'Shared by every account. Only administrators can change it.'">all accounts</span>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Client ID</label>
                            <input v-model="form.twitch_client_id" :data-field="'twitch_client_id'" :aria-invalid="!!form.errors.twitch_client_id" type="text" placeholder="Your Twitch Client ID"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                            <FieldError :message="form.errors.twitch_client_id" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Client Secret</label>
                            <input v-model="form.twitch_client_secret" :data-field="'twitch_client_secret'" :aria-invalid="!!form.errors.twitch_client_secret" type="password" autocomplete="new-password" :placeholder="secretSet('twitch_client_secret') ? 'Leave empty to keep the stored secret' : 'Your Twitch Client Secret'"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                            <FieldError :message="form.errors.twitch_client_secret" />
                            <SecretHint :stored="secretSet('twitch_client_secret')" :removing="form.clear_secrets.includes('twitch_client_secret')" :mask="secretMask('twitch_client_secret')" @remove="clearSecret('twitch_client_secret')" @undo="undoClear('twitch_client_secret')" />
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

                <!-- Sync (installation) -->
                <div v-if="isAdmin" id="section-sync" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <RefreshCw class="w-5 h-5 text-purple-500" />
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Auto Scanning</h3>
                            <span class="text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400" v-tooltip="'Shared by every account. Only administrators can change it.'">all accounts</span>
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
                        <input v-model="form.sync_frequency_minutes" :data-field="'sync_frequency_minutes'" :aria-invalid="!!form.errors.sync_frequency_minutes" type="range" min="1" max="60" step="1"
                            class="w-full h-2 rounded-full appearance-none cursor-pointer bg-gray-200 dark:bg-zinc-700 accent-purple-600
                            [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-purple-600 [&::-webkit-slider-thumb]:shadow-md [&::-webkit-slider-thumb]:hover:bg-purple-500 [&::-webkit-slider-thumb]:transition-colors
                            [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-purple-600 [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:shadow-md" />
                        <FieldError :message="form.errors.sync_frequency_minutes" />
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
                    <p class="text-xs text-gray-500 dark:text-zinc-500 mb-4">Your default filters, applied to your categories that use global settings.</p>
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Minimum Viewers</label>
                                <input v-model="form.global_min_viewers" :data-field="'global_min_viewers'" :aria-invalid="!!form.errors.global_min_viewers" type="number" min="0"
                                    class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                                <FieldError :message="form.errors.global_min_viewers" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Min Avg Viewers <span class="font-normal text-gray-400 dark:text-zinc-600">TwitchTracker</span></label>
                                <input v-model="form.global_min_avg_viewers" :data-field="'global_min_avg_viewers'" :aria-invalid="!!form.errors.global_min_avg_viewers" type="number" min="0"
                                    class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                                <FieldError :message="form.errors.global_min_avg_viewers" />
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

                <!-- Notifications: the account's alert destinations -->
                <div id="section-notifications" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <NotificationDestinations :destinations="destinations" :types="destinationTypes" :email-available="emailAvailable" />
                </div>

                <!-- Mail server (installation) -->
                <div v-if="isAdmin" id="section-mail" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center gap-2 mb-1">
                        <Mail class="w-5 h-5 text-purple-500" />
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Mail Server</h3>
                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400" v-tooltip="'Shared by every account. Only administrators can change it.'">all accounts</span>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-zinc-500 mb-4">Every account's Email destinations are sent through this SMTP server. Save, then use the test button of an Email destination to try it.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">SMTP Host</label>
                            <input v-model="form.smtp_host" :data-field="'smtp_host'" :aria-invalid="!!form.errors.smtp_host" type="text" placeholder="smtp.example.com"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                            <FieldError :message="form.errors.smtp_host" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">SMTP Port</label>
                            <input v-model="form.smtp_port" :data-field="'smtp_port'" :aria-invalid="!!form.errors.smtp_port" type="text" placeholder="587"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                            <FieldError :message="form.errors.smtp_port" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">Encryption</label>
                            <select v-model="form.smtp_encryption"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300 outline-none focus:ring-2 focus:ring-purple-500">
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="">None</option>
                            </select>
                            <FieldError :message="form.errors.smtp_encryption" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">SMTP Username</label>
                            <input v-model="form.smtp_username" :data-field="'smtp_username'" :aria-invalid="!!form.errors.smtp_username" type="text"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                            <FieldError :message="form.errors.smtp_username" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">SMTP Password</label>
                            <input v-model="form.smtp_password" :data-field="'smtp_password'" :aria-invalid="!!form.errors.smtp_password" type="password" autocomplete="new-password" :placeholder="secretSet('smtp_password') ? 'Leave empty to keep the stored password' : ''"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                            <FieldError :message="form.errors.smtp_password" />
                            <SecretHint :stored="secretSet('smtp_password')" :removing="form.clear_secrets.includes('smtp_password')" :mask="secretMask('smtp_password')" @remove="clearSecret('smtp_password')" @undo="undoClear('smtp_password')" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">From Address</label>
                            <input v-model="form.mail_from_address" :data-field="'mail_from_address'" :aria-invalid="!!form.errors.mail_from_address" type="email" placeholder="noreply@example.com"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                            <FieldError :message="form.errors.mail_from_address" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1">From Name</label>
                            <input v-model="form.mail_from_name" :data-field="'mail_from_name'" :aria-invalid="!!form.errors.mail_from_name" type="text" placeholder="StreamRadar"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                            <FieldError :message="form.errors.mail_from_name" />
                        </div>
                    </div>
                </div>

                <!-- Access / Auth (installation) -->
                <div v-if="isAdmin" id="section-auth" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center gap-2 mb-4">
                        <Lock class="w-5 h-5 text-purple-500" />
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Access Control</h3>
                    </div>
                    <div class="space-y-3 text-xs text-gray-500 dark:text-zinc-500">
                        <p>
                            Signing in is always required. Accounts and roles are managed on the
                            <Link href="/users" class="text-purple-600 dark:text-purple-400 hover:underline">Users</Link> page;
                            change your own password under <Link href="/account" class="text-purple-600 dark:text-purple-400 hover:underline">Account</Link>.
                            Single sign-on (OpenID Connect) is configured on the server through <code class="px-1 py-0.5 rounded bg-gray-100 dark:bg-zinc-800">OIDC_*</code> environment variables.
                        </p>
                        <p>
                            Outbound webhooks may only target public internet addresses. Private hosts explicitly allowed via
                            <code class="px-1 py-0.5 rounded bg-gray-100 dark:bg-zinc-800">WEBHOOK_ALLOWED_HOSTS</code>:
                            <span class="font-mono">{{ webhookAllowedHosts.length ? webhookAllowedHosts.join(', ') : 'none' }}</span>
                        </p>
                    </div>
                </div>

                <!-- Backup (administrators) -->
                <div v-if="isAdmin" id="section-backup" class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none scroll-mt-24">
                    <div class="flex items-center gap-2 mb-4">
                        <Download class="w-5 h-5 text-purple-500" />
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Backup</h3>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-zinc-500 mb-4">Export or import the installation settings and your own account's settings, tracked categories, channels, blacklist, alerts and alert destinations. Other accounts' data is not included. Secrets are never exported, so an imported destination may need its token or URL again.</p>
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
                <div v-if="isFormDirty || errorList.length" :class="errorList.length ? 'h-40' : 'h-20'"></div>
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
            <div v-if="isFormDirty || errorList.length" class="fixed bottom-0 left-0 right-0 z-30 bg-white/80 dark:bg-zinc-900/80 backdrop-blur-md py-3 px-4" style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px))">
                <div class="max-w-7xl mx-auto flex flex-wrap items-center justify-end gap-3">
                    <div v-if="errorList.length" role="alert" class="flex-1 min-w-0 text-sm text-red-600 dark:text-red-400">
                        <button type="button" @click="scrollToFirstError" class="font-medium hover:underline">Not saved: {{ errorList.length === 1 ? 'one field needs attention' : `${errorList.length} fields need attention` }}</button>
                        <ul class="text-xs mt-0.5">
                            <li v-for="e in errorList" :key="e.key" class="truncate"><span class="font-medium">{{ e.label }}:</span> {{ e.message }}</li>
                        </ul>
                    </div>
                    <button @click="saveSettings" :disabled="form.processing"
                        class="px-6 py-2.5 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors shadow-lg">
                        {{ form.processing ? 'Saving...' : 'Save Settings' }}
                    </button>
                </div>
            </div>
        </Transition>
    </AppLayout>
</template>
