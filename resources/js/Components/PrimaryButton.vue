<script setup>
import Spinner from '@/Components/UI/Spinner.vue';

defineProps({
    type: {
        type: String,
        default: 'submit',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-transparent bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 active:bg-emerald-800 disabled:opacity-50"
    >
        <Spinner v-if="loading" size="sm" />
        <slot />
    </button>
</template>
