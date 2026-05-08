<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
});

const classes = computed(() =>
    props.active
        ? 'inline-flex items-center border-b-2 border-emerald-500 px-1 pt-1 text-sm font-medium leading-5 text-slate-950 transition duration-150 ease-in-out focus:border-emerald-700 focus:outline-none'
        : 'inline-flex items-center border-b-2 border-transparent px-1 pt-1 text-sm font-medium leading-5 text-slate-500 transition duration-150 ease-in-out hover:border-slate-300 hover:text-slate-700 focus:border-slate-300 focus:text-slate-700 focus:outline-none'
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
