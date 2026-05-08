<script setup>
defineProps({
    size: {
        type: String,
        default: 'md',
    },
});

const sizes = {
    sm: 'h-4 w-4',
    md: 'h-5 w-5',
    lg: 'h-6 w-6',
};
</script>

<template>
    <span
        class="inline-block animate-spin rounded-full border-2 border-current border-r-transparent"
        :class="sizes[size] ?? sizes.md"
        aria-hidden="true"
    />
</template>
