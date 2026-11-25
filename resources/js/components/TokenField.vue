<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Braces, Check, Plus, Trash2 } from 'lucide-vue-next';
import type { AlertTemplateBlock, AlertTemplateField, AlertTemplateFields } from '@/types';
import { blocksToText, mergeBlocks, textToBlocks, tokenName, unknownTokens } from '@/utils/alertTemplate';

/**
 * Text with tokens: literal text and field chips side by side, edited in
 * place. "+ Token" inserts a field at the caret, tapping a chip swaps or
 * removes it, chips can be dragged, and "Edit as text" shows the same
 * value as `Text {Token} text`. Pasted `{Token}` text becomes chips.
 *
 * The editable area is managed by hand (Vue never renders into it): typing
 * is parsed back into blocks, and the DOM is rebuilt only when the value
 * changes from outside (text editor, reset).
 */
const props = withDefaults(defineProps<{
    modelValue: AlertTemplateBlock[];
    fields: AlertTemplateFields;
    /** Allow line breaks (the message); otherwise one line (links, labels). */
    multiline?: boolean;
    placeholder?: string;
    /** Small field: the toolbar becomes two icons inside it. */
    compact?: boolean;
    /** Accessible name of the field. */
    label: string;
    /** Background of the field, to sit on white or grey panels. */
    fieldBg?: string;
    /** Links: no spell checking. */
    spellcheck?: boolean;
}>(), { multiline: false, placeholder: '', compact: false, fieldBg: 'bg-gray-50', spellcheck: true });

const emit = defineEmits<{ 'update:modelValue': [value: AlertTemplateBlock[]] }>();

const editor = ref<HTMLDivElement | null>(null);
const menu = ref<HTMLDivElement | null>(null);
const root = ref<HTMLDivElement | null>(null);

const fieldList = computed(() => (Object.entries(props.fields) as [AlertTemplateField, AlertTemplateFields[AlertTemplateField]][])
    .map(([key, info]) => ({ key, ...info })));
const used = computed(() => new Set(props.modelValue.filter(b => b.type === 'field').map(b => (b as { field: string }).field)));
const hasTokens = computed(() => used.value.size > 0);
const isEmpty = computed(() => props.modelValue.length === 0);

// ── DOM ⇄ blocks ────────────────────────────────────────────────────

const CHIP = 'tf-chip inline-flex items-center gap-1 align-middle mx-0.5 my-px pl-1 pr-1 py-px rounded-md border text-xs font-medium leading-5 cursor-pointer select-none whitespace-nowrap '
    + 'border-purple-200 bg-purple-50 text-purple-700 hover:border-purple-300 dark:border-purple-500/30 dark:bg-purple-500/10 dark:text-purple-300';
const CHIP_ACTIVE = ['ring-2', 'ring-purple-400', 'dark:ring-purple-500'];
const GRIP = '<svg aria-hidden="true" width="10" height="14" viewBox="0 0 10 14" class="shrink-0 opacity-50 cursor-grab"><g fill="currentColor"><circle cx="3" cy="3" r="1.1"/><circle cx="7" cy="3" r="1.1"/><circle cx="3" cy="7" r="1.1"/><circle cx="7" cy="7" r="1.1"/><circle cx="3" cy="11" r="1.1"/><circle cx="7" cy="11" r="1.1"/></g></svg>';
const CROSS = '<svg aria-hidden="true" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';

function chip(field: AlertTemplateField): HTMLSpanElement {
    const el = document.createElement('span');
    el.contentEditable = 'false';
    el.draggable = true;
    el.dataset.field = field;
    el.className = CHIP;
    el.title = `{${tokenName(field)}} · tap to change, drag to move`;
    el.innerHTML = `${GRIP}<span data-label></span><button type="button" data-remove tabindex="-1" class="ml-0.5 rounded opacity-60 hover:opacity-100 hover:text-red-600">${CROSS}</button>`;
    el.querySelector('[data-label]')!.textContent = props.fields[field]?.label ?? field;
    el.querySelector('[data-remove]')!.setAttribute('aria-label', `Remove ${props.fields[field]?.label ?? field}`);
    return el;
}

function nodesFor(blocks: AlertTemplateBlock[]): Node[] {
    return blocks.map(b => (b.type === 'text' ? document.createTextNode(clean(b.value)) : chip(b.field)));
}

/** One line: line breaks become spaces. */
function clean(text: string): string {
    const normalized = text.replace(/\r\n?/g, '\n');
    return props.multiline ? normalized : normalized.replace(/\n+/g, ' ');
}

/** Blocks from DOM nodes. A trailing <br> is the browser's placeholder for the last line, not a line break. */
function parseNodes(parent: Node, out: AlertTemplateBlock[] = []): AlertTemplateBlock[] {
    const push = (value: string) => {
        value = value.replace(/[​﻿]/g, '').replace(/ /g, ' ');
        if (value) out.push({ type: 'text', value });
    };
    parent.childNodes.forEach((node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            push(node.textContent ?? '');
        } else if (node instanceof HTMLElement) {
            if (node.dataset.field) {
                out.push({ type: 'field', field: node.dataset.field as AlertTemplateField });
            } else if (node.tagName === 'BR') {
                if (node !== parent.lastChild) push('\n');
            } else if (node.tagName === 'DIV' || node.tagName === 'P') {
                // Some browsers wrap new lines in blocks.
                if (out.length) push('\n');
                parseNodes(node, out);
            } else {
                parseNodes(node, out);
            }
        }
    });
    return out;
}

function parse(): AlertTemplateBlock[] {
    const blocks = mergeBlocks(parseNodes(editor.value!));
    return props.multiline ? blocks : mergeBlocks(blocks.map(b => (b.type === 'text' ? { type: 'text', value: clean(b.value) } : b)));
}

/** Keep a <br> at the very end, so a line break the user just typed shows as a new line. */
function ensureEndBreak() {
    const el = editor.value!;
    el.querySelectorAll(':scope > br[data-end]').forEach(br => { if (br !== el.lastChild) br.remove(); });
    if (!props.multiline) return;
    const last = el.lastChild;
    if (!(last instanceof HTMLBRElement)) {
        const br = document.createElement('br');
        br.dataset.end = '';
        el.appendChild(br);
    }
}

let lastEmitted = '';

function render(blocks: AlertTemplateBlock[]) {
    const el = editor.value;
    if (!el) return;
    el.replaceChildren(...nodesFor(blocks));
    ensureEndBreak();
    lastEmitted = JSON.stringify(mergeBlocks(blocks));
}

function sync() {
    ensureEndBreak();
    const blocks = parse();
    lastEmitted = JSON.stringify(blocks);
    emit('update:modelValue', blocks);
}

watch(() => props.modelValue, (value) => {
    if (JSON.stringify(mergeBlocks(value)) !== lastEmitted) render(value);
}, { deep: true });

// ── Caret ───────────────────────────────────────────────────────────

let savedRange: Range | null = null;

function inEditor(node: Node | null): boolean {
    return !!node && !!editor.value && editor.value.contains(node);
}

function rememberSelection() {
    const sel = document.getSelection();
    if (sel?.rangeCount && inEditor(sel.anchorNode)) savedRange = sel.getRangeAt(0).cloneRange();
}

function chipOf(node: Node | null): HTMLElement | null {
    const el = node instanceof HTMLElement ? node : node?.parentElement;
    const found = el?.closest<HTMLElement>('[data-field]') ?? null;
    return found && inEditor(found) ? found : null;
}

/** Where to insert: the caret, the last known caret, or the end. */
function insertionRange(preferred?: Range | null): Range {
    const sel = document.getSelection();
    let range = preferred
        ?? (sel?.rangeCount && inEditor(sel.anchorNode) ? sel.getRangeAt(0) : null)
        ?? (savedRange && inEditor(savedRange.startContainer) ? savedRange : null);
    if (!range) {
        range = document.createRange();
        range.selectNodeContents(editor.value!);
        range.collapse(false);
        const end = editor.value!.lastChild;
        if (end instanceof HTMLBRElement) range.setStartBefore(end);
        range.collapse(true);
    }
    // Never inside a chip: right after it.
    const inside = chipOf(range.startContainer);
    if (inside) {
        range = document.createRange();
        range.setStartAfter(inside);
        range.collapse(true);
    }
    return range;
}

function placeCaretAfter(node: Node) {
    const range = document.createRange();
    range.setStartAfter(node);
    range.collapse(true);
    const sel = document.getSelection();
    sel?.removeAllRanges();
    sel?.addRange(range);
    savedRange = range.cloneRange();
}

function insertNodes(nodes: Node[], at?: Range | null) {
    if (!nodes.length) return;
    const range = insertionRange(at);
    range.deleteContents();
    const fragment = document.createDocumentFragment();
    nodes.forEach(n => fragment.appendChild(n));
    const last = nodes[nodes.length - 1];
    range.insertNode(fragment);
    editor.value!.focus({ preventScroll: true });
    placeCaretAfter(last);
    sync();
}

// ── Typing, pasting, copying ────────────────────────────────────────

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.isComposing) {
        e.preventDefault();
        if (props.multiline) insertNodes([document.createTextNode('\n')]);
    } else if (e.key === 'Escape' && menuOpen.value) {
        closeMenu();
    }
}

function onBeforeInput(e: InputEvent) {
    if (e.inputType.startsWith('format')) {
        e.preventDefault();
    } else if (e.inputType === 'insertParagraph' || e.inputType === 'insertLineBreak') {
        // Mobile keyboards, which send no Enter keydown.
        e.preventDefault();
        if (props.multiline) insertNodes([document.createTextNode('\n')]);
    }
}

function onPaste(e: ClipboardEvent) {
    e.preventDefault();
    const text = e.clipboardData?.getData('text/plain') ?? '';
    if (text) insertNodes(nodesFor(textToBlocks(clean(text), props.fields)));
}

/** Copy chips as `{Token}`, so they paste back as chips. */
function onCopy(e: ClipboardEvent, cut = false) {
    const sel = document.getSelection();
    if (!sel?.rangeCount || !inEditor(sel.anchorNode)) return;
    e.preventDefault();
    const range = sel.getRangeAt(0);
    e.clipboardData?.setData('text/plain', blocksToText(mergeBlocks(parseNodes(range.cloneContents()))));
    if (cut) {
        range.deleteContents();
        sync();
    }
}

// ── Chips: tap, remove, drag ────────────────────────────────────────

const replacing = ref<HTMLElement | null>(null);
const replacingLabel = computed(() => {
    const field = replacing.value?.dataset.field as AlertTemplateField | undefined;
    return field ? props.fields[field]?.label ?? field : '';
});

function onClick(e: MouseEvent) {
    const target = e.target as HTMLElement;
    const chipEl = chipOf(target);
    if (!chipEl) return;
    e.preventDefault();
    if (target.closest('[data-remove]')) {
        chipEl.remove();
        sync();
        closeMenu();
        return;
    }
    openMenu(chipEl);
}

let dragged: HTMLElement | null = null;

function onDragStart(e: DragEvent) {
    const chipEl = chipOf(e.target as Node);
    if (!chipEl || !e.dataTransfer) {
        // Only chips are dragged; dragged text would come back as markup.
        e.preventDefault();
        return;
    }
    dragged = chipEl;
    closeMenu();
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', `{${tokenName(chipEl.dataset.field!)}}`);
    requestAnimationFrame(() => chipEl.classList.add('opacity-40'));
}

function onDragOver(e: DragEvent) {
    e.preventDefault();
    if (e.dataTransfer) e.dataTransfer.dropEffect = dragged ? 'move' : 'copy';
}

function rangeAtPoint(x: number, y: number): Range | null {
    const doc = document as Document & { caretPositionFromPoint?: (x: number, y: number) => { offsetNode: Node; offset: number } | null };
    if (doc.caretPositionFromPoint) {
        const pos = doc.caretPositionFromPoint(x, y);
        if (!pos) return null;
        const range = document.createRange();
        range.setStart(pos.offsetNode, pos.offset);
        range.collapse(true);
        return range;
    }
    return document.caretRangeFromPoint?.(x, y) ?? null;
}

function onDrop(e: DragEvent) {
    e.preventDefault();
    let range = rangeAtPoint(e.clientX, e.clientY);
    if (range && !inEditor(range.startContainer)) range = null;

    if (dragged) {
        const chipEl = dragged;
        endDrag();
        if (range && chipEl.contains(range.startContainer)) return;
        // A marker keeps the drop position while the chip leaves its old place.
        const marker = document.createTextNode('');
        insertionRange(range).insertNode(marker);
        marker.parentNode!.insertBefore(chipEl, marker);
        marker.remove();
        editor.value!.focus({ preventScroll: true });
        placeCaretAfter(chipEl);
        sync();
    } else {
        const text = e.dataTransfer?.getData('text/plain') ?? '';
        if (text) insertNodes(nodesFor(textToBlocks(clean(text), props.fields)), range);
    }
}

function endDrag() {
    dragged?.classList.remove('opacity-40');
    dragged = null;
}

// ── Token menu ──────────────────────────────────────────────────────

const menuOpen = ref(false);

function openMenu(chipEl: HTMLElement | null = null) {
    rememberSelection();
    replacing.value?.classList.remove(...CHIP_ACTIVE);
    replacing.value = chipEl;
    chipEl?.classList.add(...CHIP_ACTIVE);
    menuOpen.value = true;
    nextTick(() => menu.value?.querySelector<HTMLButtonElement>('button')?.focus({ preventScroll: true }));
}

function closeMenu() {
    replacing.value?.classList.remove(...CHIP_ACTIVE);
    replacing.value = null;
    menuOpen.value = false;
}

function toggleAddMenu() {
    if (menuOpen.value && !replacing.value) closeMenu();
    else openMenu();
}

function choose(field: AlertTemplateField) {
    const target = replacing.value;
    closeMenu();
    if (target && inEditor(target)) {
        const next = chip(field);
        target.replaceWith(next);
        editor.value!.focus({ preventScroll: true });
        placeCaretAfter(next);
        sync();
    } else {
        insertNodes([chip(field)]);
    }
}

function removeReplacing() {
    const target = replacing.value;
    closeMenu();
    if (target && inEditor(target)) {
        target.remove();
        sync();
    }
}

function onMenuKeydown(e: KeyboardEvent) {
    const items = [...(menu.value?.querySelectorAll<HTMLButtonElement>('button') ?? [])];
    const index = items.indexOf(document.activeElement as HTMLButtonElement);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const next = (index + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items[next]?.focus();
    } else if (e.key === 'Escape') {
        e.preventDefault();
        closeMenu();
        editor.value?.focus({ preventScroll: true });
    } else if (e.key === 'Tab') {
        closeMenu();
    }
}

function onDocumentPointerDown(e: PointerEvent) {
    if (menuOpen.value && root.value && !root.value.contains(e.target as Node)) closeMenu();
}

// ── Text editor ─────────────────────────────────────────────────────

const showText = ref(false);
const text = ref('');
const textFocused = ref(false);
const unknown = computed(() => unknownTokens(text.value, props.fields));
const textRows = computed(() => Math.min(8, Math.max(2, text.value.split('\n').length)));

watch([() => props.modelValue, showText], () => {
    if (!textFocused.value) text.value = blocksToText(props.modelValue);
}, { deep: true, immediate: true });

function onText() {
    emit('update:modelValue', textToBlocks(clean(text.value), props.fields));
}

function onTextBlur() {
    textFocused.value = false;
    text.value = blocksToText(props.modelValue);
}

// ── Lifecycle ───────────────────────────────────────────────────────

onMounted(() => {
    render(props.modelValue);
    document.addEventListener('selectionchange', rememberSelection);
    document.addEventListener('pointerdown', onDocumentPointerDown);
});

onBeforeUnmount(() => {
    document.removeEventListener('selectionchange', rememberSelection);
    document.removeEventListener('pointerdown', onDocumentPointerDown);
});

const tokenButton = 'inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-md border transition-colors '
    + 'border-gray-300 dark:border-zinc-600 text-gray-600 dark:text-zinc-300 hover:border-purple-300 hover:text-purple-700 dark:hover:border-purple-500/50 dark:hover:text-purple-300';
</script>

<template>
    <div ref="root" class="relative">
        <div :class="['relative flex items-start rounded-lg focus-within:ring-2 focus-within:ring-purple-500 dark:bg-zinc-800', fieldBg]">
            <div ref="editor" contenteditable="true" role="textbox" :aria-label="label" :aria-multiline="multiline" :spellcheck="spellcheck"
                :class="['flex-1 min-w-0 px-2.5 py-1 text-sm leading-7 text-gray-900 dark:text-white outline-none break-words',
                    multiline ? 'whitespace-pre-wrap min-h-[4.5rem]' : 'whitespace-pre-wrap min-h-9']"
                @input="sync" @keydown="onKeydown" @beforeinput="onBeforeInput" @paste="onPaste" @copy="onCopy($event)" @cut="onCopy($event, true)"
                @click="onClick" @dragstart="onDragStart" @dragover="onDragOver" @drop="onDrop" @dragend="endDrag"></div>
            <span v-if="isEmpty" aria-hidden="true" class="pointer-events-none absolute left-3 top-1 text-sm leading-7 text-gray-400 dark:text-zinc-500 truncate max-w-[80%]">{{ placeholder }}</span>
            <div v-if="compact" class="flex items-center gap-0.5 p-1 shrink-0">
                <button type="button" @click="toggleAddMenu" :aria-expanded="menuOpen" aria-haspopup="menu" title="Add token"
                    class="p-1.5 rounded-md text-gray-400 hover:text-purple-600 hover:bg-white dark:hover:bg-zinc-700"><Plus class="w-3.5 h-3.5" /></button>
                <button type="button" @click="showText = !showText" :aria-pressed="showText" :title="showText ? 'Hide text editor' : 'Edit as text'"
                    :class="['p-1.5 rounded-md hover:bg-white dark:hover:bg-zinc-700', showText ? 'text-purple-600' : 'text-gray-400 hover:text-purple-600']"><Braces class="w-3.5 h-3.5" /></button>
            </div>
        </div>

        <div v-if="!compact" class="flex items-center gap-3 mt-1.5">
            <button type="button" @click="toggleAddMenu" :aria-expanded="menuOpen" aria-haspopup="menu" :class="tokenButton">
                <Plus class="w-3 h-3" /> Token
            </button>
            <span v-if="hasTokens" class="text-xs text-gray-400 dark:text-zinc-500 hidden sm:inline">Tap a token to edit it</span>
            <button type="button" @click="showText = !showText" class="ml-auto text-xs font-medium text-purple-600 dark:text-purple-400 hover:underline">
                {{ showText ? 'Hide text editor' : 'Edit as text' }}
            </button>
        </div>

        <!-- Token menu -->
        <div v-if="menuOpen" ref="menu" role="menu" @keydown="onMenuKeydown"
            class="absolute z-30 left-0 top-full mt-1 w-full max-w-sm max-h-72 overflow-y-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg ring-1 ring-black/5 dark:ring-white/10 py-1">
            <div v-if="replacing" class="px-3 pt-1.5 pb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-zinc-500">Replace “{{ replacingLabel }}” with</div>
            <button v-for="f in fieldList" :key="f.key" type="button" role="menuitem" @click="choose(f.key)"
                class="w-full flex items-start gap-2 px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-zinc-800 focus:bg-gray-50 dark:focus:bg-zinc-800 outline-none">
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ f.label }}</span>
                    <span class="block text-xs text-gray-500 dark:text-zinc-400 truncate">{{ f.description }}<template v-if="f.sample">, e.g. {{ f.sample }}</template></span>
                </span>
                <Check v-if="used.has(f.key)" class="w-4 h-4 mt-0.5 shrink-0 text-purple-600 dark:text-purple-400" />
            </button>
            <button v-if="replacing" type="button" role="menuitem" @click="removeReplacing"
                class="w-full flex items-center gap-2 px-3 py-2 mt-1 border-t border-gray-100 dark:border-zinc-800 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 focus:bg-red-50 dark:focus:bg-red-500/10 outline-none">
                <Trash2 class="w-3.5 h-3.5" /> Remove token
            </button>
        </div>

        <!-- Text editor -->
        <div v-if="showText" class="mt-2 space-y-1">
            <textarea v-if="multiline" v-model="text" :rows="textRows" :aria-label="`${label} as text`" spellcheck="false"
                @input="onText" @focus="textFocused = true" @blur="onTextBlur"
                :class="['w-full px-3 py-2 font-mono text-sm rounded-lg text-gray-900 dark:text-white dark:bg-zinc-800 outline-none ring-2 ring-purple-200 dark:ring-purple-500/30 focus:ring-purple-500 resize-y', fieldBg]"></textarea>
            <input v-else v-model="text" type="text" :aria-label="`${label} as text`" spellcheck="false"
                @input="onText" @focus="textFocused = true" @blur="onTextBlur"
                :class="['w-full px-3 py-1.5 font-mono text-sm rounded-lg text-gray-900 dark:text-white dark:bg-zinc-800 outline-none ring-2 ring-purple-200 dark:ring-purple-500/30 focus:ring-purple-500', fieldBg]" />
            <p v-if="unknown.length" class="text-[11px] text-amber-600 dark:text-amber-400">Not a token, sent as text: {{ unknown.join(', ') }}</p>
            <p v-else class="text-[11px] text-gray-400 dark:text-zinc-500">Write <code class="font-mono">{Token}</code> for a token, e.g. <code class="font-mono">{StreamerName}</code>.</p>
        </div>
    </div>
</template>
