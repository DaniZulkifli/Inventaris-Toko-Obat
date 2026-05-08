<script setup>
import Spinner from '@/Components/UI/Spinner.vue';

defineProps({
    type: {
        type: String,
        default: 'button',
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
        class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-transparent bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 active:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-50"
    >
        <Spinner v-if="loading" size="sm" />
        <slot />
    </button>
</template>
