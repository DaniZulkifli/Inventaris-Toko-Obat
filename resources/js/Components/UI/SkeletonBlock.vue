<script setup>
defineProps({
    lines: {
        type: Number,
        default: 1,
    },
});
</script>

<template>
    <div class="animate-pulse space-y-2">
        <div
            v-for="line in lines"
            :key="line"
            class="h-4 rounded-md bg-slate-200"
            :class="line === lines ? 'w-2/3' : 'w-full'"
        />
    </div>
</template>
