<script setup>
import { nextTick, ref, watch } from 'vue';
import { X } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import IconButton from './IconButton.vue';
import UiButton from './UiButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    maxWidth: { type: String, default: '2xl' },
    processing: { type: Boolean, default: false },
    submitLabel: { type: String, default: 'Simpan' },
    closeable: { type: Boolean, default: true },
});

const emit = defineEmits(['close', 'submit']);
const panel = ref(null);

const focusableSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

watch(() => props.show, async (visible) => {
    if (!visible) {
        return;
    }

    await nextTick();
    panel.value?.querySelector(focusableSelector)?.focus();
});

const trapFocus = (event) => {
    if (event.key !== 'Tab') {
        return;
    }

    const focusable = [...(panel.value?.querySelectorAll(focusableSelector) ?? [])]
        .filter((element) => !element.disabled && element.offsetParent !== null);

    if (!focusable.length) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
};
</script>

<template>
    <Modal :show="show" :max-width="maxWidth" :closeable="closeable && !processing" @close="emit('close')">
        <form ref="panel" class="max-h-[85vh] overflow-hidden" @submit.prevent="emit('submit')" @keydown="trapFocus">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ title }}</h2>
                    <p v-if="description" class="mt-1 text-sm text-slate-500">{{ description }}</p>
                </div>
                <IconButton label="Tutup modal" variant="ghost" size="sm" :disabled="processing" @click="emit('close')">
                    <X class="h-4 w-4" />
                </IconButton>
            </div>

            <div class="max-h-[calc(85vh-9rem)] overflow-y-auto px-6 py-5">
                <slot />
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                <UiButton variant="secondary" :disabled="processing" @click="emit('close')">Batal</UiButton>
                <UiButton type="submit" :loading="processing">{{ submitLabel }}</UiButton>
            </div>
        </form>
    </Modal>
</template>
