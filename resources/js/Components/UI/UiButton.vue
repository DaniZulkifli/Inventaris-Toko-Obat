<script setup>
import Spinner from './Spinner.vue';

defineProps({
    type: {
        type: String,
        default: 'button',
    },
    variant: {
        type: String,
        default: 'primary',
    },
    size: {
        type: String,
        default: 'md',
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

const variants = {
    primary: 'border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700 focus:ring-emerald-500',
    secondary: 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 focus:ring-emerald-500',
    danger: 'border-red-600 bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
    ghost: 'border-transparent bg-transparent text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 focus:ring-emerald-500',
};

const sizes = {
    sm: 'min-h-8 px-3 text-xs',
    md: 'min-h-10 px-4 text-sm',
    lg: 'min-h-11 px-5 text-sm',
};
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        class="inline-flex items-center justify-center gap-2 rounded-md border font-semibold shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
        :class="[variants[variant] ?? variants.primary, sizes[size] ?? sizes.md]"
    >
        <Spinner v-if="loading" size="sm" />
        <slot />
    </button>
</template>
