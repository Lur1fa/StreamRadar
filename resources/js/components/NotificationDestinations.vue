<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { Bell, Plus, Send, SquarePen, Trash2, X, WandSparkles, Loader2 } from 'lucide-vue-next';
import ConfirmModal from '@/components/ConfirmModal.vue';
import FieldError from '@/components/FieldError.vue';
import { useAuth } from '@/composables/useAuth';
import { useDocumentKeydown } from '@/composables/useDocumentKeydown';
import { formatDate, requestErrorMessage } from '@/utils';
import type { DestinationType, DestinationTypeInfo, NotificationDestination } from '@/types';

/** `types` is the server's list of destination types (DestinationType::forBrowser()). */
const props = defineProps<{ destinations: NotificationDestination[]; types: DestinationTypeInfo[]; emailAvailable: boolean }>();
const { isAdmin } = useAuth();

const typeLabel = (type: string) => props.types.find(t => t.value === type)?.label ?? type;

/** Field names as shown in the dialog, for "Saved secrets" and "Missing". */
const LABELS: Record<string, string> = {
    server_url: 'Server URL', topic: 'Topic', username: 'Username', password: 'Password', access_token: 'Access token', title_prefix: 'Title prefix',
    url: 'URL', auth_username: 'Username', auth_password: 'Password', auth_token: 'Token', headers: 'Header values', query: 'Query parameter values',
    to: 'To address(es)', subject_prefix: 'Subject prefix', bot_token: 'Bot token', chat_id: 'Chat ID', webhook_url: 'Webhook URL',
};

/** The plain settings a new destination of this type starts with. Webhook header and query names live in `form.rows`. */
function defaultConfig(type: DestinationType): Record<string, string> {
    const { headers, query, ...plain } = props.types.find(t => t.value === type)?.defaultConfig ?? {};
    return plain;
}

// ── Dialog ──────────────────────────────────────────────────────────
// Secrets never come back from the server. An empty secret field keeps the
// stored value ("(unchanged)"); a webhook's header and query values work the
// same way, row by row.
type Row = { name: string; value: string; original: string | null };

const showDialog = ref(false);
const editing = ref<NotificationDestination | null>(null);
const form = useForm({
    name: '',
    type: 'ntfy' as DestinationType,
    enabled: true,
    config: defaultConfig('ntfy'),
    secrets: {} as Record<string, string>,
    clear_secrets: [] as string[],
    // Not `headers` / `query`: those names are taken by the form object itself.
    rows: { headers: [] as Row[], query: [] as Row[] },
});

form.transform((data) => {
    const { rows, ...rest } = data;
    if (data.type !== 'webhook') return rest as any;
    return {
        ...rest,
        config: { ...data.config, headers: rows.headers.map(r => r.name.trim()), query: rows.query.map(r => r.name.trim()) },
        secrets: { ...data.secrets, headers: rows.headers.map(r => r.value), query: rows.query.map(r => r.value) },
    } as any;
});

function rowsFrom(names: unknown): Row[] {
    return Array.isArray(names) ? names.map((n: string) => ({ name: n, value: '', original: n })) : [];
}

/** Fill the dialog for a type, keeping the stored settings when it is the destination's own type. */
function loadType(type: DestinationType) {
    const own = editing.value?.type === type ? editing.value : null;
    const { headers, query, ...plain } = own?.config ?? {};
    form.type = type;
    form.config = { ...defaultConfig(type), ...plain };
    form.secrets = {};
    form.clear_secrets = [];
    form.rows = { headers: rowsFrom(headers), query: rowsFrom(query) };
    form.clearErrors();
}

/** Switching the type in the dialog: a suggested name follows it, a name of your own stays. */
function changeType(type: DestinationType) {
    if (form.name === suggestedName.value) form.name = '';
    loadType(type);
}

function openCreate() {
    editing.value = null;
    form.name = '';
    form.enabled = true;
    loadType('ntfy');
    showDialog.value = true;
}

function openEdit(destination: NotificationDestination) {
    editing.value = destination;
    form.name = destination.name;
    form.enabled = destination.enabled;
    loadType(destination.type);
    showDialog.value = true;
}

function close() {
    showDialog.value = false;
}

function stored(key: string): boolean {
    return !!editing.value && editing.value.type === form.type && !!editing.value.secrets_set[key] && !form.clear_secrets.includes(key);
}
function secretPlaceholder(key: string, fallback = ''): string {
    if (!stored(key)) return fallback;
    const mask = editing.value?.secret_masks[key];
    return mask ? `(unchanged) ${mask}` : '(unchanged)';
}
function removeSecret(key: string) {
    if (!form.clear_secrets.includes(key)) form.clear_secrets.push(key);
    form.secrets[key] = '';
}
function undoRemove(key: string) {
    form.clear_secrets = form.clear_secrets.filter(k => k !== key);
}
const removable = (key: string) => !!editing.value && editing.value.type === form.type && !!editing.value.secrets_set[key];

function rowPlaceholder(row: Row): string {
    return row.original !== null && row.name.trim() === row.original ? '(unchanged)' : 'Value';
}

const suggestedName = computed(() => {
    const c = form.config;
    const host = (url?: string) => { try { return url ? new URL(url).host : ''; } catch { return ''; } };
    switch (form.type) {
        case 'ntfy': return c.topic ? `ntfy · ${c.topic}` : 'ntfy';
        case 'webhook': {
            const h = host(form.secrets.url) || host(stored('url') ? editing.value?.secret_masks.url : '');
            return h ? `Webhook · ${h}` : 'Webhook';
        }
        case 'email': {
            const first = (c.to || '').split(/[\s,;]+/).filter(Boolean)[0];
            return first ? `Email · ${first}` : 'Email';
        }
        case 'telegram': return c.chat_id ? `Telegram · ${c.chat_id}` : 'Telegram';
        default: return 'Discord';
    }
});

function submit() {
    if (!form.name.trim()) form.name = suggestedName.value;
    const options = { preserveScroll: true, onSuccess: () => { showDialog.value = false; } };
    if (editing.value) form.put(`/settings/destinations/${editing.value.id}`, options);
    else form.post('/settings/destinations', options);
}

const errors = computed(() => form.errors as Record<string, string>);
const err = (key: string) => errors.value[key];
// Errors on fields the dialog has no place for (should not happen, but never hide one).
const otherErrors = computed(() => Object.entries(errors.value)
    .filter(([key]) => !/^(name|type|config\.|secrets\.)/.test(key))
    .map(([, message]) => message));

useDocumentKeydown(showDialog, (e) => { if (e.key === 'Escape') close(); });

// ── List actions ────────────────────────────────────────────────────
const testing = ref<number | null>(null);
const results = reactive<Record<number, { success: boolean; message: string }>>({});

async function test(destination: NotificationDestination) {
    testing.value = destination.id;
    delete results[destination.id];
    try {
        const { data } = await window.axios.post(`/settings/destinations/${destination.id}/test`);
        results[destination.id] = data;
    } catch (e) {
        results[destination.id] = {
            success: false,
            message: requestErrorMessage(e, 'Request failed.', 'Too many tests. Wait a minute and try again.'),
        };
    } finally {
        testing.value = null;
    }
}

const deleting = ref<NotificationDestination | null>(null);
function destroy() {
    if (!deleting.value) return;
    router.delete(`/settings/destinations/${deleting.value.id}`, { preserveScroll: true, onFinish: () => { deleting.value = null; } });
}

function savedSecrets(d: NotificationDestination): string {
    const names = Object.entries(d.secrets_set).filter(([, set]) => set).map(([key]) => LABELS[key] ?? key);
    if (d.type === 'webhook') {
        const headers = d.config.headers?.length ?? 0;
        const query = d.config.query?.length ?? 0;
        if (headers && !d.missing.includes('headers')) names.push(headers === 1 ? '1 header value' : `${headers} header values`);
        if (query && !d.missing.includes('query')) names.push(query === 1 ? '1 query value' : `${query} query values`);
    }
    return names.join(', ');
}

const label = 'block text-xs font-medium text-gray-600 dark:text-zinc-400 mb-1';
const input = 'w-full px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500 aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-red-400';
const hint = 'text-[11px] text-gray-400 dark:text-zinc-500 mt-1';
const smallButton = 'px-2.5 py-1 text-xs font-medium rounded-md bg-gray-100 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300 hover:bg-gray-200 dark:hover:bg-zinc-700 transition-colors';
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3 mb-1">
            <div class="flex items-center gap-2">
                <Bell class="w-5 h-5 text-purple-500" />
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Notifications</h3>
            </div>
            <button type="button" @click="openCreate"
                class="px-3.5 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors flex items-center gap-1.5">
                <Plus class="w-4 h-4" /> Add Destination
            </button>
        </div>
        <p class="text-xs text-gray-500 dark:text-zinc-500 mb-4">Where your alerts go. Each alert rule picks the types it sends to; every enabled destination of that type receives it.</p>

        <div v-if="destinations.length === 0" class="py-8 text-center rounded-lg border border-dashed border-gray-200 dark:border-zinc-800">
            <p class="text-sm text-gray-500 dark:text-zinc-400">No destinations yet.</p>
            <p class="text-xs text-gray-400 dark:text-zinc-600 mt-1">Add ntfy, a webhook, e-mail, Telegram or Discord.</p>
        </div>

        <ul v-else class="space-y-3">
            <li v-for="d in destinations" :key="d.id" class="rounded-lg border border-gray-200 dark:border-zinc-800 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-gray-900 dark:text-white break-all">{{ d.name }}</span>
                            <span v-if="d.enabled" class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400">Enabled</span>
                            <span v-else class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400">Disabled</span>
                            <span class="text-[11px] font-medium px-2 py-0.5 rounded-full border border-gray-200 dark:border-zinc-700 text-gray-600 dark:text-zinc-300">{{ typeLabel(d.type) }}</span>
                            <span v-if="d.missing.length" class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400">Incomplete</span>
                        </div>
                        <p v-if="d.updated_at" class="text-xs text-gray-500 dark:text-zinc-500 mt-1.5">Updated {{ formatDate(d.updated_at) }}</p>
                        <p v-if="savedSecrets(d)" class="text-xs text-gray-500 dark:text-zinc-500 mt-0.5">Saved secrets: {{ savedSecrets(d) }}</p>
                        <p v-if="d.missing.length" class="text-xs text-amber-600 dark:text-amber-400 mt-0.5">Missing: {{ d.missing.map(k => LABELS[k] ?? k).join(', ') }}</p>
                    </div>
                    <div class="flex items-center gap-0.5 shrink-0">
                        <button type="button" @click="test(d)" :disabled="testing === d.id" v-tooltip="'Send a test alert'" aria-label="Send a test alert"
                            class="p-2 rounded-lg text-gray-500 dark:text-zinc-400 hover:text-purple-600 dark:hover:text-purple-400 hover:bg-gray-50 dark:hover:bg-zinc-800 disabled:opacity-50 transition-colors">
                            <Loader2 v-if="testing === d.id" class="w-4 h-4 animate-spin" />
                            <Send v-else class="w-4 h-4" />
                        </button>
                        <button type="button" @click="openEdit(d)" v-tooltip="'Edit'" aria-label="Edit"
                            class="p-2 rounded-lg text-gray-500 dark:text-zinc-400 hover:text-purple-600 dark:hover:text-purple-400 hover:bg-gray-50 dark:hover:bg-zinc-800 transition-colors">
                            <SquarePen class="w-4 h-4" />
                        </button>
                        <button type="button" @click="deleting = d" v-tooltip="'Delete'" aria-label="Delete"
                            class="p-2 rounded-lg text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors">
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>
                </div>
                <p v-if="results[d.id]" role="status" :class="['text-xs mt-2', results[d.id].success ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400']">{{ results[d.id].message }}</p>
            </li>
        </ul>

        <ConfirmModal :show="!!deleting" :title="`Delete &quot;${deleting?.name}&quot;?`" message="Alerts will no longer be sent to this destination." confirm-label="Delete"
            @confirm="destroy" @cancel="deleting = null" />

        <!-- Add / edit dialog -->
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-150" enter-from-class="opacity-0" enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="showDialog" class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 overflow-y-auto">
                    <div class="fixed inset-0 bg-black/40 backdrop-blur-sm" @click="close" />
                    <form @submit.prevent="submit" novalidate role="dialog" aria-modal="true" aria-labelledby="destination-dialog-title"
                        class="relative w-full max-w-2xl my-auto bg-white dark:bg-zinc-900 rounded-2xl shadow-xl dark:shadow-none">
                        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-zinc-800">
                            <h2 id="destination-dialog-title" class="text-lg font-bold text-gray-900 dark:text-white">{{ editing ? 'Edit Destination' : 'New Destination' }}</h2>
                            <button type="button" @click="close" aria-label="Close" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-zinc-200 hover:bg-gray-50 dark:hover:bg-zinc-800">
                                <X class="w-5 h-5" />
                            </button>
                        </div>

                        <div class="px-6 py-5 space-y-5">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="destination-name" :class="label">Name</label>
                                    <div class="flex gap-2">
                                        <input id="destination-name" v-model="form.name" type="text" maxlength="100" :placeholder="suggestedName" :aria-invalid="!!err('name')" :class="input" />
                                        <button type="button" @click="form.name = suggestedName" v-tooltip="'Suggest a name'" aria-label="Suggest a name"
                                            class="px-2.5 rounded-lg bg-gray-50 dark:bg-zinc-800 text-purple-600 dark:text-purple-400 hover:bg-gray-100 dark:hover:bg-zinc-700 transition-colors">
                                            <WandSparkles class="w-4 h-4" />
                                        </button>
                                    </div>
                                    <FieldError :message="err('name')" />
                                </div>
                                <div>
                                    <label for="destination-type" :class="label">Type</label>
                                    <select id="destination-type" :value="form.type" @change="changeType(($event.target as HTMLSelectElement).value as DestinationType)" :class="input">
                                        <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                                    </select>
                                    <FieldError :message="err('type')" />
                                </div>
                            </div>

                            <label class="inline-flex items-center gap-3 cursor-pointer select-none">
                                <button type="button" role="switch" :aria-checked="form.enabled" @click="form.enabled = !form.enabled"
                                    :class="['relative h-6 w-11 shrink-0 rounded-full transition-colors', form.enabled ? 'bg-purple-600' : 'bg-gray-200 dark:bg-zinc-700']">
                                    <span :class="['absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform', form.enabled ? 'translate-x-5' : 'translate-x-0']" />
                                </button>
                                <span class="text-sm font-medium text-gray-700 dark:text-zinc-300">Enabled</span>
                            </label>

                            <div class="rounded-xl border border-gray-200 dark:border-zinc-800 p-4 space-y-4">
                                <!-- ntfy -->
                                <template v-if="form.type === 'ntfy'">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label :class="label">Server URL</label>
                                            <input v-model="form.config.server_url" type="url" autocomplete="off" placeholder="https://ntfy.sh" :aria-invalid="!!err('config.server_url')" :class="input" />
                                            <FieldError :message="err('config.server_url')" />
                                        </div>
                                        <div>
                                            <label :class="label">Topic</label>
                                            <input v-model="form.config.topic" type="text" autocomplete="off" placeholder="streamradar" :aria-invalid="!!err('config.topic')" :class="input" />
                                            <FieldError :message="err('config.topic')" />
                                        </div>
                                        <div>
                                            <label :class="label">Access Token</label>
                                            <input v-model="form.secrets.access_token" type="password" autocomplete="new-password" :placeholder="secretPlaceholder('access_token', 'tk_…')" :aria-invalid="!!err('secrets.access_token')" :class="input" />
                                            <FieldError :message="err('secrets.access_token')" />
                                            <p v-if="stored('access_token')" :class="hint"><button type="button" @click="removeSecret('access_token')" class="text-red-500 hover:underline">Remove token</button></p>
                                            <p v-else-if="removable('access_token')" class="text-[11px] text-amber-600 dark:text-amber-400 mt-1">Removed on save · <button type="button" @click="undoRemove('access_token')" class="hover:underline">undo</button></p>
                                        </div>
                                        <div>
                                            <label :class="label">Username</label>
                                            <input v-model="form.config.username" type="text" autocomplete="off" :aria-invalid="!!err('config.username')" :class="input" />
                                            <FieldError :message="err('config.username')" />
                                        </div>
                                        <div>
                                            <label :class="label">Password</label>
                                            <input v-model="form.secrets.password" type="password" autocomplete="new-password" :placeholder="secretPlaceholder('password')" :aria-invalid="!!err('secrets.password')" :class="input" />
                                            <FieldError :message="err('secrets.password')" />
                                        </div>
                                        <div>
                                            <label :class="label">Title Prefix</label>
                                            <input v-model="form.config.title_prefix" type="text" maxlength="64" placeholder="StreamRadar" :aria-invalid="!!err('config.title_prefix')" :class="input" />
                                            <FieldError :message="err('config.title_prefix')" />
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-400 dark:text-zinc-500">For a protected topic, use an access token (sent as an <code>Authorization: Bearer</code> header) or a username and password. The token wins if both are set.</p>
                                </template>

                                <!-- Webhook -->
                                <template v-else-if="form.type === 'webhook'">
                                    <div class="grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-4">
                                        <div>
                                            <label :class="label">Method</label>
                                            <select v-model="form.config.method" :class="input">
                                                <option value="POST">POST</option>
                                                <option value="PUT">PUT</option>
                                                <option value="PATCH">PATCH</option>
                                            </select>
                                            <FieldError :message="err('config.method')" />
                                        </div>
                                        <div>
                                            <label :class="label">URL</label>
                                            <input v-model="form.secrets.url" type="url" autocomplete="off" :placeholder="secretPlaceholder('url', 'https://example.com/hook')" :aria-invalid="!!err('secrets.url')" :class="input" />
                                            <FieldError :message="err('secrets.url')" />
                                        </div>
                                    </div>

                                    <div v-for="list in (['query', 'headers'] as const)" :key="list">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-xs font-medium text-gray-600 dark:text-zinc-400">{{ list === 'query' ? 'Query Parameters' : 'Headers' }}</span>
                                            <button type="button" @click="form.rows[list].push({ name: '', value: '', original: null })" :disabled="form.rows[list].length >= 20" :class="smallButton">
                                                {{ list === 'query' ? 'Add query' : 'Add header' }}
                                            </button>
                                        </div>
                                        <p v-if="form.rows[list].length === 0" class="text-xs text-gray-400 dark:text-zinc-600">{{ list === 'query' ? 'No query parameters.' : 'No headers.' }}</p>
                                        <div v-for="(row, i) in form.rows[list]" :key="i" class="mb-2">
                                            <div class="flex gap-2">
                                                <input v-model="row.name" type="text" autocomplete="off" :placeholder="list === 'query' ? 'Name' : 'Header'" :aria-label="list === 'query' ? 'Parameter name' : 'Header name'"
                                                    :aria-invalid="!!err(`config.${list}.${i}`)" :class="[input, 'sm:w-2/5']" />
                                                <input v-model="row.value" type="password" autocomplete="new-password" :placeholder="rowPlaceholder(row)" :aria-label="list === 'query' ? 'Parameter value' : 'Header value'"
                                                    :aria-invalid="!!err(`secrets.${list}.${i}`)" :class="input" />
                                                <button type="button" @click="form.rows[list].splice(i, 1)" aria-label="Remove" class="px-2 rounded-lg text-gray-400 hover:text-red-500 hover:bg-gray-50 dark:hover:bg-zinc-800">
                                                    <X class="w-4 h-4" />
                                                </button>
                                            </div>
                                            <FieldError :message="err(`config.${list}.${i}`)" />
                                            <FieldError :message="err(`secrets.${list}.${i}`)" />
                                        </div>
                                        <p v-if="form.rows[list].length" :class="hint">Values are stored encrypted and not shown again.</p>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label :class="label">Authentication</label>
                                            <select v-model="form.config.auth" :class="input">
                                                <option value="none">None</option>
                                                <option value="basic">Basic (username and password)</option>
                                                <option value="bearer">Bearer token</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div v-if="form.config.auth === 'basic'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label :class="label">Username</label>
                                            <input v-model="form.config.auth_username" type="text" autocomplete="off" :aria-invalid="!!err('config.auth_username')" :class="input" />
                                            <FieldError :message="err('config.auth_username')" />
                                        </div>
                                        <div>
                                            <label :class="label">Password</label>
                                            <input v-model="form.secrets.auth_password" type="password" autocomplete="new-password" :placeholder="secretPlaceholder('auth_password')" :aria-invalid="!!err('secrets.auth_password')" :class="input" />
                                            <FieldError :message="err('secrets.auth_password')" />
                                        </div>
                                    </div>
                                    <div v-else-if="form.config.auth === 'bearer'">
                                        <label :class="label">Token</label>
                                        <input v-model="form.secrets.auth_token" type="password" autocomplete="new-password" :placeholder="secretPlaceholder('auth_token')" :aria-invalid="!!err('secrets.auth_token')" :class="input" />
                                        <FieldError :message="err('secrets.auth_token')" />
                                    </div>
                                    <p class="text-xs text-gray-400 dark:text-zinc-500">Each batch of alerts is sent as JSON: <code>{"event": "stream.alerts.batch", "count": …, "alerts": [...]}</code>.</p>
                                </template>

                                <!-- Email -->
                                <template v-else-if="form.type === 'email'">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label :class="label">To Address(es)</label>
                                            <input v-model="form.config.to" type="text" autocomplete="off" placeholder="you@example.com" :aria-invalid="!!err('config.to')" :class="input" />
                                            <FieldError v-if="err('config.to')" :message="err('config.to')" />
                                            <p v-else :class="hint">Separate several addresses with commas.</p>
                                        </div>
                                        <div>
                                            <label :class="label">Subject Prefix</label>
                                            <input v-model="form.config.subject_prefix" type="text" maxlength="64" placeholder="[StreamRadar]" :aria-invalid="!!err('config.subject_prefix')" :class="input" />
                                            <FieldError :message="err('config.subject_prefix')" />
                                        </div>
                                    </div>
                                    <p v-if="!emailAvailable" class="text-xs text-amber-600 dark:text-amber-400">
                                        {{ isAdmin ? 'No mail server is set up yet. Set it up under Mail Server on this page.' : 'E-mail delivery is not set up on this server yet. Ask an administrator.' }}
                                    </p>
                                    <p v-else class="text-xs text-gray-400 dark:text-zinc-500">Sent through the mail server {{ isAdmin ? 'under Mail Server on this page' : 'an administrator set up' }}.</p>
                                </template>

                                <!-- Telegram -->
                                <template v-else-if="form.type === 'telegram'">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label :class="label">Bot Token</label>
                                            <input v-model="form.secrets.bot_token" type="password" autocomplete="new-password" :placeholder="secretPlaceholder('bot_token', 'From @BotFather')" :aria-invalid="!!err('secrets.bot_token')" :class="input" />
                                            <FieldError :message="err('secrets.bot_token')" />
                                        </div>
                                        <div>
                                            <label :class="label">Chat ID</label>
                                            <input v-model="form.config.chat_id" type="text" inputmode="numeric" autocomplete="off" placeholder="123456789" :aria-invalid="!!err('config.chat_id')" :class="input" />
                                            <FieldError :message="err('config.chat_id')" />
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-400 dark:text-zinc-500">Create a bot via <strong>@BotFather</strong>, send /start to it, then enter your numeric chat ID.</p>
                                </template>

                                <!-- Discord -->
                                <template v-else>
                                    <div>
                                        <label :class="label">Webhook URL</label>
                                        <input v-model="form.secrets.webhook_url" type="url" autocomplete="off" :placeholder="secretPlaceholder('webhook_url', 'https://discord.com/api/webhooks/…')" :aria-invalid="!!err('secrets.webhook_url')" :class="input" />
                                        <FieldError :message="err('secrets.webhook_url')" />
                                    </div>
                                    <p class="text-xs text-gray-400 dark:text-zinc-500">In Discord: Server Settings &rarr; Integrations &rarr; Webhooks &rarr; New Webhook, then copy its URL.</p>
                                </template>
                            </div>

                            <FieldError v-for="message in otherErrors" :key="message" :message="message" />
                        </div>

                        <div class="flex justify-end gap-2 px-6 py-4 border-t border-gray-100 dark:border-zinc-800">
                            <button type="button" @click="close"
                                class="px-4 py-2 text-sm font-medium rounded-lg border border-gray-200 dark:border-zinc-700 text-gray-700 dark:text-zinc-300 hover:bg-gray-50 dark:hover:bg-zinc-800 transition-colors">Cancel</button>
                            <button type="submit" :disabled="form.processing"
                                class="px-4 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors flex items-center gap-1.5">
                                <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
                                Save Destination
                            </button>
                        </div>
                    </form>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
