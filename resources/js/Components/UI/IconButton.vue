<script setup>
import Spinner from './Spinner.vue';

defineProps({
    label: {
        type: String,
        required: true,
    },
    type: {
        type: String,
        default: 'button',
    },
    variant: {
        type: String,
        default: 'secondary',
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
    secondary: 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-950 focus:ring-emerald-500',
    danger: 'border-red-200 bg-white text-red-600 hover:bg-red-50 focus:ring-red-500',
    ghost: 'border-transparent bg-transparent text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 focus:ring-emerald-500',
};

const sizes = {
    sm: 'h-8 w-8',
    md: 'h-10 w-10',
    lg: 'h-11 w-11',
};
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :aria-label="label"
        :title="label"
        class="inline-flex shrink-0 items-center justify-center rounded-md border shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
        :class="[variants[variant] ?? variants.secondary, sizes[size] ?? sizes.md]"
    >
        <Spinner v-if="loading" size="sm" />
        <slot v-else />
    </button>
</template>
