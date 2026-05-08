<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ToastStack from '@/Components/UI/ToastStack.vue';
import { usePageToasts } from '@/Composables/usePageToasts';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const { messages: toastMessages, removeToast } = usePageToasts();
const storeName = computed(() => page.props.store?.store_name || 'Toko Obat');
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-900">
        <ToastStack :messages="toastMessages" @dismiss="removeToast" />

        <div class="grid min-h-screen lg:grid-cols-[minmax(0,1fr)_30rem]">
            <section class="hidden bg-emerald-700 px-10 py-12 text-white lg:flex lg:flex-col lg:justify-between">
                <Link :href="route('login')" class="flex items-center gap-3">
                    <span class="flex h-12 w-12 items-center justify-center rounded-md bg-white text-emerald-700">
                        <ApplicationLogo class="h-8 w-8 fill-current" />
                    </span>
                    <span class="leading-tight">
                        <span class="block text-lg font-semibold">{{ storeName }}</span>
                        <span class="block text-sm text-emerald-100">Inventaris Toko Obat</span>
                    </span>
                </Link>

                <div class="max-w-lg">
                    <p class="text-sm font-semibold uppercase text-emerald-100">Sistem Inventaris</p>
                    <h1 class="mt-3 text-4xl font-semibold leading-tight">{{ storeName }}</h1>
                    <p class="mt-4 text-base leading-7 text-emerald-50">Panel operasional internal.</p>
                </div>
            </section>

            <main class="flex min-h-screen items-center justify-center px-4 py-10 sm:px-6 lg:px-10">
                <div class="w-full max-w-md">
                    <div class="mb-6 flex items-center gap-3 lg:hidden">
                        <Link :href="route('login')" class="flex h-12 w-12 items-center justify-center rounded-md bg-emerald-600 text-white">
                            <ApplicationLogo class="h-8 w-8 fill-current" />
                        </Link>
                        <div>
                            <div class="text-lg font-semibold text-slate-950">{{ storeName }}</div>
                            <div class="text-sm text-emerald-700">Inventaris Toko Obat</div>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-md border border-slate-200 bg-white p-6 shadow-sm">
                        <slot />
                    </div>
                </div>
            </main>
        </div>
    </div>
</template>
