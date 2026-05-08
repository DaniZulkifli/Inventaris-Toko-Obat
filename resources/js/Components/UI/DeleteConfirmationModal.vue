<script setup>
import { AlertTriangle } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import UiButton from './UiButton.vue';

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: 'Hapus data?' },
    itemName: { type: String, default: 'data ini' },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'confirm']);
</script>

<template>
    <Modal :show="show" max-width="md" :closeable="!processing" @close="emit('close')">
        <div class="px-6 py-5">
            <div class="flex gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-red-50 text-red-600">
                    <AlertTriangle class="h-5 w-5" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ title }}</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        Data <span class="font-semibold text-slate-950">{{ itemName }}</span> akan dihapus jika belum
                        dipakai transaksi.
                    </p>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <UiButton variant="secondary" :disabled="processing" @click="emit('close')">Batal</UiButton>
                <UiButton variant="danger" :loading="processing" @click="emit('confirm')">Hapus</UiButton>
            </div>
        </div>
    </Modal>
</template>
