<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Bell, Trash2, CheckCircle, XCircle, X, AlertTriangle, Pencil, ChevronDown } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import AlertTemplateBuilder from '@/components/AlertTemplateBuilder.vue';
import type { AlertRule, Category, AlertNotificationTemplate } from '@/types';

const props = defineProps<{ alertRules: AlertRule[]; categories: Category[]; emailConfigured: boolean; discordConfigured: boolean; telegramConfigured: boolean; webhookConfigured: boolean }>();

// ── New alert form ──────────────────────────────────────────────────
const showForm = ref(false);
const showFilters = ref(false);
const channelMode = ref<'anyone' | 'specific'>('anyone');
const categoryMode = ref<'any' | 'specific' | 'tagged'>('any');
const form = useForm({
    name: '',
    streamer_login: '',
    category_id: null as number | null,
    category_ids: [] as number[],
    category_tags: [] as string[],
    min_viewers: null as number | null,
    min_avg_viewers: null as number | null,
    language: '',
    keywords: [] as string[],
    notify_email: props.emailConfigured,
    notify_discord: props.discordConfigured,
    notify_telegram: props.telegramConfigured,
    notify_webhook: props.webhookConfigured,
    notify_on_category_change: false,
    notify_on_stream_start: true,
    notification_template: null as AlertNotificationTemplate | null,
});

const availableTags = computed(() => {
    const tags = new Set<string>();
    props.categories.forEach(c => c.tags?.forEach(t => tags.add(t)));
    return Array.from(tags).sort();
});

const kwInput = ref('');
function addKw() { const v = kwInput.value.trim(); if (v && !form.keywords.includes(v)) form.keywords.push(v); kwInput.value = ''; }
function removeKw(kw: string) { form.keywords = form.keywords.filter(k => k !== kw); }

// Auto-generate name
const autoName = computed(() => {
    const who = channelMode.value === 'specific' && form.streamer_login ? form.streamer_login : 'Anyone';
    let cat = 'any category';
    if (categoryMode.value === 'specific' && form.category_ids.length) cat = form.category_ids.map(id => props.categories.find(c => c.id === id)?.name).filter(Boolean).join(', ') || 'categories';
    else if (categoryMode.value === 'tagged' && form.category_tags.length) cat = form.category_tags.join(', ');
    return `${who} — ${cat}`;
});
function resetForm() {
    form.reset();
    channelMode.value = 'anyone';
    categoryMode.value = 'any';
    showFilters.value = false;
    kwInput.value = '';
}

function submit() {
    if (!form.name) form.name = autoName.value;
    if (channelMode.value === 'anyone') form.streamer_login = '';
    if (categoryMode.value !== 'specific') { form.category_id = null; form.category_ids = []; }
    if (categoryMode.value !== 'tagged') form.category_tags = [];
    form.post('/alerts', { preserveScroll: true, onSuccess: () => { resetForm(); showForm.value = false; } });
}

const hasFilters = computed(() => !!(form.min_viewers || form.language || form.keywords.length));

// ── Edit ────────────────────────────────────────────────────────────
const editingId = ref<number | null>(null);
const editChannelMode = ref<'anyone' | 'specific'>('anyone');
const editCategoryMode = ref<'any' | 'specific' | 'tagged'>('any');
const editShowFilters = ref(false);
const editForm = useForm({
    name: '', streamer_login: '', category_id: null as number | null, category_ids: [] as number[], category_tags: [] as string[],
    min_viewers: null as number | null, min_avg_viewers: null as number | null, language: '', keywords: [] as string[],
    notify_email: true, notify_discord: true, notify_telegram: true, notify_webhook: false,
    notify_on_category_change: false,
    notify_on_stream_start: true,
    notification_template: null as AlertNotificationTemplate | null,
});
const editKwInput = ref('');
function addEditKw() { const v = editKwInput.value.trim(); if (v && !editForm.keywords.includes(v)) editForm.keywords.push(v); editKwInput.value = ''; }
function removeEditKw(kw: string) { editForm.keywords = editForm.keywords.filter(k => k !== kw); }

function startEdit(rule: AlertRule) {
    editingId.value = rule.id;
    editForm.name = rule.name;
    editForm.streamer_login = rule.streamer_login || '';
    editForm.category_id = rule.category_id;
    editForm.category_ids = rule.category_ids || [];
    editForm.min_viewers = rule.min_viewers;
    editForm.min_avg_viewers = rule.min_avg_viewers;
    editForm.language = rule.language || '';
    editForm.keywords = rule.keywords ? [...rule.keywords] : [];
    editForm.notify_email = rule.notify_email;
    editForm.notify_discord = rule.notify_discord;
    editForm.notify_telegram = rule.notify_telegram;
    editForm.notify_webhook = rule.notify_webhook;
    editForm.notify_on_category_change = rule.notify_on_category_change;
    editForm.notify_on_stream_start = rule.notify_on_stream_start;
    editForm.category_tags = rule.category_tags || [];
    editForm.notification_template = rule.notification_template ? { version: 1, blocks: [...rule.notification_template.blocks] } : null;
    editChannelMode.value = rule.streamer_login ? 'specific' : 'anyone';
    editCategoryMode.value = rule.category_tags?.length ? 'tagged' : (rule.category_ids?.length || rule.category_id) ? 'specific' : 'any';
    editShowFilters.value = !!(rule.min_viewers || rule.min_avg_viewers || rule.language || rule.keywords?.length);
    editKwInput.value = '';
}

function saveEdit(rule: AlertRule) {
    if (editChannelMode.value === 'anyone') editForm.streamer_login = '';
    if (editCategoryMode.value !== 'specific') { editForm.category_id = null; editForm.category_ids = []; }
    if (editCategoryMode.value !== 'tagged') editForm.category_tags = [];
    editForm.put(`/alerts/${rule.id}`, { preserveScroll: true, onSuccess: () => { editingId.value = null; } });
}

// ── Actions ─────────────────────────────────────────────────────────
function toggleActive(rule: AlertRule) { router.put(`/alerts/${rule.id}`, { is_active: !rule.is_active }, { preserveScroll: true }); }
const deletingRule = ref<AlertRule | null>(null);
function deleteRule() { if (!deletingRule.value) return; router.delete(`/alerts/${deletingRule.value.id}`, { preserveScroll: true, onSuccess: () => { deletingRule.value = null; } }); }

function formatTime(dateStr: string): string {
    const d = new Date(dateStr);
    const now = new Date();
    const diffMin = Math.floor((now.getTime() - d.getTime()) / 60000);
    if (diffMin < 1) return 'just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return `${diffH}h ago`;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

// Shared warnings check
function unconfiguredWarnings(f: { notify_email: boolean; notify_discord: boolean; notify_telegram: boolean; notify_webhook: boolean }) {
    const w: string[] = [];
    if (f.notify_email && !props.emailConfigured) w.push('Email not configured — Settings → Email / SMTP');
    if (f.notify_discord && !props.discordConfigured) w.push('Discord not configured — Settings → Discord');
    if (f.notify_telegram && !props.telegramConfigured) w.push('Telegram not configured — Settings → Telegram');
    if (f.notify_webhook && !props.webhookConfigured) w.push('Webhook not configured — Settings → Webhook');
    return w;
}
</script>

<template>
    <AppLayout>
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Alert Rules</h2>
            <button @click="showForm = !showForm; if (!showForm) resetForm(); editingId = null"
                class="px-4 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors flex items-center gap-1.5">
                <Plus v-if="!showForm" class="w-4 h-4" />
                <X v-else class="w-4 h-4" />
                {{ showForm ? 'Cancel' : 'New Alert' }}
            </button>
        </div>

        <!-- ── New Alert: Sentence Builder ─────────────────────────── -->
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0 -translate-y-2">
            <div v-if="showForm" class="mb-6 bg-white dark:bg-zinc-900 rounded-xl shadow-sm dark:shadow-none overflow-hidden">
                <div class="p-5 space-y-5">

                    <!-- Row 1: Notify me via -->
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">Notify me via</span>
                        <label :class="['inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all border',
                            form.notify_discord ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 border-indigo-200 dark:border-indigo-500/30' : 'bg-gray-50 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                            <input type="checkbox" v-model="form.notify_discord" class="sr-only" />
                            Discord
                        </label>
                        <label :class="['inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all border',
                            form.notify_email ? 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-500/30' : 'bg-gray-50 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                            <input type="checkbox" v-model="form.notify_email" class="sr-only" />
                            Email
                        </label>
                        <label :class="['inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all border',
                            form.notify_telegram ? 'bg-sky-50 dark:bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-200 dark:border-sky-500/30' : 'bg-gray-50 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                            <input type="checkbox" v-model="form.notify_telegram" class="sr-only" />
                            Telegram
                        </label>
                        <label :class="['inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all border',
                            form.notify_webhook ? 'bg-orange-50 dark:bg-orange-500/10 text-orange-700 dark:text-orange-300 border-orange-200 dark:border-orange-500/30' : 'bg-gray-50 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                            <input type="checkbox" v-model="form.notify_webhook" class="sr-only" />
                            Webhook
                        </label>
                    </div>

                    <!-- Row 2: when ... -->
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">when</span>
                        <select v-model="channelMode" class="px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none focus:ring-2 focus:ring-purple-500 font-medium">
                            <option value="anyone">anyone</option>
                            <option value="specific">specific channel</option>
                        </select>
                        <input v-if="channelMode === 'specific'" v-model="form.streamer_login" type="text" placeholder="channel login"
                            class="px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none focus:ring-2 focus:ring-purple-500 w-40" />
                    </div>

                    <!-- Row 3: triggers -->
                    <div class="flex flex-col gap-2 pl-0.5">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" v-model="form.notify_on_stream_start" :disabled="!form.notify_on_category_change" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" />
                            <span class="text-sm text-gray-700 dark:text-zinc-300">goes live</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" v-model="form.notify_on_category_change" :disabled="!form.notify_on_stream_start" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" />
                            <span class="text-sm text-gray-700 dark:text-zinc-300">changes game / category</span>
                        </label>
                    </div>

                    <!-- Row 4: in category -->
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">in</span>
                        <select v-model="categoryMode" class="px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none focus:ring-2 focus:ring-purple-500 font-medium">
                            <option value="any">any tracked category</option>
                            <option value="specific">specific categories</option>
                            <option v-if="availableTags.length" value="tagged">tagged categories</option>
                        </select>
                        <div v-if="categoryMode === 'specific'" class="flex flex-wrap gap-1">
                            <label v-for="cat in categories" :key="cat.id"
                                :class="['inline-flex px-2.5 py-1 rounded-full text-xs font-medium cursor-pointer transition-all border',
                                    form.category_ids.includes(cat.id) ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-500/30' : 'bg-gray-50 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                                <input type="checkbox" :value="cat.id" v-model="form.category_ids" class="sr-only" />
                                {{ cat.name }}
                            </label>
                        </div>
                        <div v-if="categoryMode === 'tagged'" class="flex flex-wrap gap-1">
                            <label v-for="tag in availableTags" :key="tag"
                                :class="['inline-flex px-2.5 py-1 rounded-full text-xs font-medium cursor-pointer transition-all border',
                                    form.category_tags.includes(tag) ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-500/30' : 'bg-gray-50 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                                <input type="checkbox" :value="tag" v-model="form.category_tags" class="sr-only" />
                                {{ tag }}
                            </label>
                        </div>
                    </div>

                    <!-- Filters toggle -->
                    <button @click="showFilters = !showFilters" class="flex items-center gap-1.5 text-xs font-medium text-gray-400 dark:text-zinc-500 hover:text-gray-600 dark:hover:text-zinc-300 transition-colors">
                        <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-180': showFilters }" />
                        {{ showFilters ? 'Hide filters' : 'Add filters' }}
                        <span v-if="hasFilters && !showFilters" class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                    </button>

                    <!-- Collapsible filters -->
                    <div v-if="showFilters" class="space-y-3 pl-0.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-gray-600 dark:text-zinc-400">with at least</span>
                            <input v-model.number="form.min_viewers" type="number" min="0" placeholder="0"
                                class="w-20 px-3 py-1.5 text-sm text-center rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none" />
                            <span class="text-sm text-gray-600 dark:text-zinc-400">viewers</span>
                            <span class="text-sm text-gray-600 dark:text-zinc-400">and avg</span>
                            <input v-model.number="form.min_avg_viewers" type="number" min="0" placeholder="0"
                                class="w-20 px-3 py-1.5 text-sm text-center rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none" />
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-gray-600 dark:text-zinc-400">streaming in</span>
                            <input v-model="form.language" type="text" placeholder="any" autocomplete="off"
                                class="w-16 px-3 py-1.5 text-sm text-center rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none placeholder:text-gray-300 dark:placeholder:text-zinc-600" />
                            <span class="text-sm text-gray-600 dark:text-zinc-400">language</span>
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm text-gray-600 dark:text-zinc-400">title contains</span>
                                <div class="flex flex-wrap gap-1" v-if="form.keywords.length">
                                    <span v-for="kw in form.keywords" :key="kw" class="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-300 rounded-full">
                                        {{ kw }} <button @click="removeKw(kw)" class="hover:text-red-500"><X class="w-3 h-3" /></button>
                                    </span>
                                </div>
                            </div>
                            <div class="flex gap-2 mt-1.5">
                                <input v-model="kwInput" @keydown.enter.prevent="addKw" placeholder="add keyword"
                                    class="flex-1 px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none" />
                                <button @click="addKw" class="px-3 py-1.5 text-xs font-medium bg-gray-100 dark:bg-zinc-700 text-gray-600 dark:text-zinc-300 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-600">Add</button>
                            </div>
                        </div>
                    </div>

                    <!-- Alert name -->
                    <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-gray-100 dark:border-zinc-800">
                        <span class="text-xs text-gray-400 dark:text-zinc-500">Alert name <span class="text-gray-300 dark:text-zinc-600">optional</span></span>
                        <input v-model="form.name" type="text" :placeholder="autoName"
                            class="flex-1 px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none placeholder:text-gray-400 dark:placeholder:text-zinc-500" />
                    </div>

                    <AlertTemplateBuilder v-model="form.notification_template" />

                    <!-- Warnings -->
                    <div v-if="unconfiguredWarnings(form).length" class="space-y-1.5">
                        <div v-for="w in unconfiguredWarnings(form)" :key="w" class="flex items-center gap-2 px-3 py-2 text-xs font-medium bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400 rounded-lg">
                            <AlertTriangle class="w-3.5 h-3.5 shrink-0" /> {{ w }}
                        </div>
                    </div>

                    <!-- Submit -->
                    <button @click="submit" :disabled="form.processing"
                        class="w-full py-2.5 text-sm font-semibold bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors">
                        Create Alert
                    </button>
                </div>
            </div>
        </Transition>

        <!-- ── Empty state ─────────────────────────────────────────── -->
        <div v-if="alertRules.length === 0 && !showForm" class="text-center py-16">
            <Bell class="w-16 h-16 mx-auto text-gray-300 dark:text-zinc-700" :stroke-width="1" />
            <h3 class="mt-4 text-lg font-semibold text-gray-700 dark:text-zinc-300">No alert rules</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-zinc-500">Create a rule to get notified when streams match your criteria.</p>
        </div>

        <!-- ── Rules List ──────────────────────────────────────────── -->
        <TransitionGroup v-else name="list" tag="div" class="space-y-3">
            <div v-for="rule in alertRules" :key="rule.id"
                :class="['bg-white dark:bg-zinc-900 rounded-xl shadow-sm dark:shadow-none transition-all overflow-hidden', !rule.is_active && 'opacity-50']">
                <div class="flex items-start justify-between gap-4 p-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ rule.name }}</h3>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5 text-xs text-gray-500 dark:text-zinc-500">
                            <span v-if="rule.streamer_login" class="px-2 py-0.5 bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400 rounded-full font-medium">@{{ rule.streamer_login }}</span>
                            <span v-else class="px-2 py-0.5 bg-gray-50 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400 rounded-full">Anyone</span>
                            <span class="text-gray-300 dark:text-zinc-700">&middot;</span>
                            <span v-if="rule.category_tags?.length" class="text-purple-600 dark:text-purple-400">{{ rule.category_tags.join(', ') }}</span>
                            <span v-else-if="rule.category_ids?.length">{{ rule.category_ids.map(id => categories.find(c => c.id === id)?.name).filter(Boolean).join(', ') }}</span>
                            <span v-else>{{ rule.category?.name || 'Any category' }}</span>
                            <template v-if="rule.notify_on_category_change">
                                <span class="text-gray-300 dark:text-zinc-700">&middot;</span>
                                <span class="text-amber-600 dark:text-amber-400">game changes</span>
                            </template>
                            <template v-if="rule.min_viewers">
                                <span class="text-gray-300 dark:text-zinc-700">&middot;</span>
                                <span>&ge;{{ rule.min_viewers }} viewers</span>
                            </template>
                            <template v-if="rule.language">
                                <span class="text-gray-300 dark:text-zinc-700">&middot;</span>
                                <span>{{ rule.language }}</span>
                            </template>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                            <span v-if="rule.notify_discord" class="text-[10px] font-medium text-indigo-500">Discord</span>
                            <span v-if="rule.notify_email" class="text-[10px] font-medium text-blue-500">Email</span>
                            <span v-if="rule.notify_telegram" class="text-[10px] font-medium text-sky-500">Telegram</span>
                            <span v-if="rule.notify_webhook" class="text-[10px] font-medium text-orange-500">Webhook</span>
                            <span v-if="rule.latest_tracking" class="text-[10px] text-gray-400 dark:text-zinc-600 ml-1">Last: {{ formatTime(rule.latest_tracking.triggered_at) }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <button @click="editingId === rule.id ? editingId = null : startEdit(rule)"
                            class="p-2 text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 transition-colors">
                            <Pencil class="w-4 h-4" />
                        </button>
                        <button @click="toggleActive(rule)" :class="['p-2 rounded-lg transition-colors', rule.is_active ? 'text-green-500 hover:bg-green-50 dark:hover:bg-green-500/10' : 'text-gray-400 hover:bg-gray-50 dark:hover:bg-zinc-800']">
                            <CheckCircle v-if="rule.is_active" class="w-4 h-4" />
                            <XCircle v-else class="w-4 h-4" />
                        </button>
                        <button @click="deletingRule = rule" class="p-2 text-gray-400 hover:text-red-600 dark:hover:text-red-400 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 transition-colors">
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                <!-- ── Edit Panel (sentence style) ─────────────────── -->
                <div v-if="editingId === rule.id" class="p-5 bg-gray-50 dark:bg-zinc-950/50 space-y-4 border-t border-gray-100 dark:border-zinc-800">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">Notify me via</span>
                        <label :class="['inline-flex px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all border', editForm.notify_discord ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 border-indigo-200 dark:border-indigo-500/30' : 'bg-gray-100 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                            <input type="checkbox" v-model="editForm.notify_discord" class="sr-only" /> Discord
                        </label>
                        <label :class="['inline-flex px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all border', editForm.notify_email ? 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-500/30' : 'bg-gray-100 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                            <input type="checkbox" v-model="editForm.notify_email" class="sr-only" /> Email
                        </label>
                        <label :class="['inline-flex px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all border', editForm.notify_telegram ? 'bg-sky-50 dark:bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-200 dark:border-sky-500/30' : 'bg-gray-100 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                            <input type="checkbox" v-model="editForm.notify_telegram" class="sr-only" /> Telegram
                        </label>
                        <label :class="['inline-flex px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all border', editForm.notify_webhook ? 'bg-orange-50 dark:bg-orange-500/10 text-orange-700 dark:text-orange-300 border-orange-200 dark:border-orange-500/30' : 'bg-gray-100 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                            <input type="checkbox" v-model="editForm.notify_webhook" class="sr-only" /> Webhook
                        </label>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">when</span>
                        <select v-model="editChannelMode" class="px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none focus:ring-2 focus:ring-purple-500 font-medium">
                            <option value="anyone">anyone</option>
                            <option value="specific">specific channel</option>
                        </select>
                        <input v-if="editChannelMode === 'specific'" v-model="editForm.streamer_login" type="text" placeholder="channel login"
                            class="px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none focus:ring-2 focus:ring-purple-500 w-40" />
                    </div>
                    <div class="flex flex-col gap-2 pl-0.5">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" v-model="editForm.notify_on_stream_start" :disabled="!editForm.notify_on_category_change" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" />
                            <span class="text-sm text-gray-700 dark:text-zinc-300">goes live</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" v-model="editForm.notify_on_category_change" :disabled="!editForm.notify_on_stream_start" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" />
                            <span class="text-sm text-gray-700 dark:text-zinc-300">changes game / category</span>
                        </label>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">in</span>
                        <select v-model="editCategoryMode" class="px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none focus:ring-2 focus:ring-purple-500 font-medium">
                            <option value="any">any tracked category</option>
                            <option value="specific">specific categories</option>
                            <option v-if="availableTags.length" value="tagged">tagged categories</option>
                        </select>
                        <div v-if="editCategoryMode === 'specific'" class="flex flex-wrap gap-1">
                            <label v-for="cat in categories" :key="cat.id"
                                :class="['inline-flex px-2.5 py-1 rounded-full text-xs font-medium cursor-pointer transition-all border',
                                    editForm.category_ids.includes(cat.id) ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-500/30' : 'bg-gray-50 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                                <input type="checkbox" :value="cat.id" v-model="editForm.category_ids" class="sr-only" />
                                {{ cat.name }}
                            </label>
                        </div>
                        <div v-if="editCategoryMode === 'tagged'" class="flex flex-wrap gap-1">
                            <label v-for="tag in availableTags" :key="tag"
                                :class="['inline-flex px-2.5 py-1 rounded-full text-xs font-medium cursor-pointer transition-all border',
                                    editForm.category_tags.includes(tag) ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-500/30' : 'bg-gray-50 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent']">
                                <input type="checkbox" :value="tag" v-model="editForm.category_tags" class="sr-only" />
                                {{ tag }}
                            </label>
                        </div>
                    </div>

                    <button @click="editShowFilters = !editShowFilters" class="flex items-center gap-1.5 text-xs font-medium text-gray-400 dark:text-zinc-500 hover:text-gray-600 dark:hover:text-zinc-300 transition-colors">
                        <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-180': editShowFilters }" />
                        {{ editShowFilters ? 'Hide filters' : 'Add filters' }}
                    </button>

                    <div v-if="editShowFilters" class="space-y-3 pl-0.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-gray-600 dark:text-zinc-400">with at least</span>
                            <input v-model.number="editForm.min_viewers" type="number" min="0" placeholder="0"
                                class="w-20 px-3 py-1.5 text-sm text-center rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none" />
                            <span class="text-sm text-gray-600 dark:text-zinc-400">viewers</span>
                            <span class="text-sm text-gray-600 dark:text-zinc-400">and avg</span>
                            <input v-model.number="editForm.min_avg_viewers" type="number" min="0" placeholder="0"
                                class="w-20 px-3 py-1.5 text-sm text-center rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none" />
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-gray-600 dark:text-zinc-400">streaming in</span>
                            <input v-model="editForm.language" type="text" placeholder="any" autocomplete="off"
                                class="w-16 px-3 py-1.5 text-sm text-center rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none" />
                            <span class="text-sm text-gray-600 dark:text-zinc-400">language</span>
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm text-gray-600 dark:text-zinc-400">title contains</span>
                                <div class="flex flex-wrap gap-1" v-if="editForm.keywords.length">
                                    <span v-for="kw in editForm.keywords" :key="kw" class="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-300 rounded-full">
                                        {{ kw }} <button @click="removeEditKw(kw)" class="hover:text-red-500"><X class="w-3 h-3" /></button>
                                    </span>
                                </div>
                            </div>
                            <div class="flex gap-2 mt-1.5">
                                <input v-model="editKwInput" @keydown.enter.prevent="addEditKw" placeholder="add keyword"
                                    class="flex-1 px-3 py-1.5 text-sm rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none" />
                                <button @click="addEditKw" class="px-3 py-1.5 text-xs font-medium bg-gray-100 dark:bg-zinc-700 text-gray-600 dark:text-zinc-300 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-600">Add</button>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-gray-200 dark:border-zinc-800">
                        <span class="text-xs text-gray-400 dark:text-zinc-500">Alert name</span>
                        <input v-model="editForm.name" type="text"
                            class="flex-1 px-3 py-1.5 text-sm rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none" />
                    </div>

                    <AlertTemplateBuilder v-model="editForm.notification_template" />

                    <div v-if="unconfiguredWarnings(editForm).length" class="space-y-1.5">
                        <div v-for="w in unconfiguredWarnings(editForm)" :key="w" class="flex items-center gap-2 px-3 py-2 text-xs font-medium bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400 rounded-lg">
                            <AlertTriangle class="w-3.5 h-3.5 shrink-0" /> {{ w }}
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button @click="saveEdit(rule)" :disabled="editForm.processing || !editForm.name"
                            class="px-4 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors">Save</button>
                        <button @click="editingId = null" class="px-4 py-2 text-sm font-medium text-gray-600 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800 rounded-lg transition-colors">Cancel</button>
                    </div>
                </div>
            </div>
        </TransitionGroup>

        <ConfirmModal :show="!!deletingRule" :title="`Delete &quot;${deletingRule?.name}&quot;?`" message="This alert rule and its tracking history will be permanently deleted." confirm-label="Delete" @confirm="deleteRule" @cancel="deletingRule = null" />
    </AppLayout>
</template>
