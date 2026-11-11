<script setup lang="ts">
import { ref } from 'vue';
import { GripVertical, X, Plus } from 'lucide-vue-next';
import type { AlertNotificationTemplate, AlertTemplateBlock, AlertTemplateField } from '@/types';

const props = defineProps<{ modelValue: AlertNotificationTemplate | null }>();
const emit = defineEmits<{ 'update:modelValue': [value: AlertNotificationTemplate | null] }>();

const fields: { field: AlertTemplateField; label: string }[] = [
    { field: 'streamer_name', label: 'Streamer name' }, { field: 'streamer_login', label: 'Streamer login' },
    { field: 'title', label: 'Title' }, { field: 'category', label: 'Category' }, { field: 'viewers', label: 'Viewers' },
    { field: 'language', label: 'Language' }, { field: 'url', label: 'Twitch URL' }, { field: 'alert_names', label: 'Alert name' },
    { field: 'tags', label: 'Tags' }, { field: 'started_at', label: 'Start time' },
];
const text = ref('');
const dragging = ref<number | null>(null);

function blocks(): AlertTemplateBlock[] { return props.modelValue?.blocks ? [...props.modelValue.blocks] : []; }
function update(next: AlertTemplateBlock[]) { emit('update:modelValue', next.length ? { version: 1, blocks: next } : null); }
function addField(field: AlertTemplateField) { update([...blocks(), { type: 'field', field }]); }
function addText() { if (text.value.trim()) { update([...blocks(), { type: 'text', value: text.value }]); text.value = ''; } }
function remove(index: number) { const next = blocks(); next.splice(index, 1); update(next); }
function move(index: number, target: number) {
    const next = blocks(); const [item] = next.splice(index, 1); next.splice(target, 0, item); update(next); dragging.value = null;
}
function label(block: AlertTemplateBlock) { return block.type === 'text' ? `“${block.value}”` : fields.find(f => f.field === block.field)?.label ?? block.field; }
</script>

<template>
    <div class="space-y-3 pt-3 border-t border-gray-100 dark:border-zinc-800">
        <div>
            <div class="text-xs font-semibold text-gray-500 dark:text-zinc-400 mb-2">Notification message <span class="font-normal">(optional)</span></div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-1.5">
                <button v-for="item in fields" :key="item.field" type="button" @click="addField(item.field)"
                    class="flex items-center gap-1 px-2 py-1.5 text-left text-xs rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-600 dark:text-zinc-300 hover:bg-purple-50 dark:hover:bg-purple-500/10 hover:text-purple-700 transition-colors">
                    <Plus class="w-3 h-3 shrink-0" /> {{ item.label }}
                </button>
            </div>
            <div class="flex gap-2 mt-2">
                <input v-model="text" @keydown.enter.prevent="addText" placeholder="Add your own text" type="text"
                    class="flex-1 px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none" />
                <button type="button" @click="addText" class="px-3 py-1.5 text-xs font-medium bg-gray-100 dark:bg-zinc-700 text-gray-600 dark:text-zinc-300 rounded-lg">Add text</button>
            </div>
        </div>
        <div v-if="modelValue?.blocks.length" class="space-y-1.5">
            <div class="text-xs text-gray-400 dark:text-zinc-500">Message order (drag to reorder)</div>
            <div v-for="(block, index) in modelValue.blocks" :key="index" draggable="true"
                @dragstart="dragging = index" @dragover.prevent @drop="dragging !== null && move(dragging, index)"
                class="flex items-center gap-2 px-2.5 py-2 rounded-lg bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 text-sm text-gray-700 dark:text-zinc-300">
                <GripVertical class="w-4 h-4 text-gray-400 shrink-0 cursor-grab" />
                <span class="flex-1 truncate" :class="block.type === 'text' && 'text-purple-600 dark:text-purple-400'">{{ label(block) }}</span>
                <button type="button" @click="remove(index)" class="text-gray-400 hover:text-red-500"><X class="w-3.5 h-3.5" /></button>
            </div>
        </div>
        <button v-if="modelValue" type="button" @click="update([])" class="text-xs text-gray-400 hover:text-red-500">Use built-in message instead</button>
    </div>
</template>
