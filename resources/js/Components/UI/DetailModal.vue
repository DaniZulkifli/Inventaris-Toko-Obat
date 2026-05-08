<script setup>
import { X } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import IconButton from './IconButton.vue';

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    maxWidth: { type: String, default: '2xl' },
});

const emit = defineEmits(['close']);
</script>

<template>
    <Modal :show="show" :max-width="maxWidth" @close="emit('close')">
        <div class="max-h-[85vh] overflow-hidden">
            <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-slate-950">{{ title }}</h2>
                <IconButton label="Tutup detail" variant="ghost" size="sm" @click="emit('close')">
                    <X class="h-4 w-4" />
                </IconButton>
            </div>

            <div class="max-h-[calc(85vh-4.5rem)] overflow-y-auto px-6 py-5">
                <slot />
            </div>
        </div>
    </Modal>
</template>
