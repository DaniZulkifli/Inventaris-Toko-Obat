<script setup>
import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-vue-next';

defineProps({
    messages: {
        type: Array,
        default: () => [],
    },
});

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
    <div class="fixed right-4 top-4 z-[60] w-[min(24rem,calc(100vw-2rem))] space-y-2">
        <div
            v-for="message in messages"
            :key="message.id ?? message.text"
            class="flex items-start gap-3 rounded-md border p-4 text-sm shadow-lg"
            :class="tones[message.type ?? 'info']"
            role="status"
        >
            <component :is="icons[message.type ?? 'info']" class="mt-0.5 h-5 w-5 shrink-0" />
            <div>
                <div v-if="message.title" class="font-semibold">{{ message.title }}</div>
                <div>{{ message.text }}</div>
            </div>
        </div>
    </div>
</template>
