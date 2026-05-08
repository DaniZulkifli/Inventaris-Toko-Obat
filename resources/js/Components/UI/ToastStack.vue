<script setup>
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-vue-next';

defineProps({
    messages: {
        type: Array,
        default: () => [],
    },
});

defineEmits(['dismiss']);

const icons = {
    success: CheckCircle2,
    error: XCircle,
    warning: AlertTriangle,
    info: Info,
};

const tones = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    error: 'border-red-200 bg-red-50 text-red-800',
    warning: 'border-amber-200 bg-amber-50 text-amber-800',
    info: 'border-sky-200 bg-sky-50 text-sky-800',
};
</script>

<template>
    <div class="fixed right-4 top-4 z-[70] w-[min(24rem,calc(100vw-2rem))] space-y-2">
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="translate-y-2 opacity-0"
        >
            <div
                v-for="message in messages"
                :key="message.id ?? message.text"
                class="flex items-start gap-3 rounded-md border p-4 text-sm shadow-lg"
                :class="tones[message.type ?? 'info']"
                role="status"
            >
                <component :is="icons[message.type ?? 'info']" class="mt-0.5 h-5 w-5 shrink-0" />
                <div class="min-w-0 flex-1">
                    <div v-if="message.title" class="font-semibold">{{ message.title }}</div>
                    <div>{{ message.text }}</div>
                </div>
                <button
                    type="button"
                    class="-mr-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded text-current opacity-70 hover:bg-white/60 hover:opacity-100"
                    aria-label="Tutup notifikasi"
                    @click="$emit('dismiss', message.id)"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
