<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Link
        :href="href"
        class="block w-full px-4 py-2 text-start text-sm leading-5 text-slate-700 transition duration-150 ease-in-out hover:bg-emerald-50 hover:text-emerald-700 focus:bg-emerald-50 focus:outline-none"
    >
        <slot />
    </Link>
</template>
