<script setup>
import { computed, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    ArchiveX,
    ArrowLeftRight,
    ChevronDown,
    ClipboardList,
    Database,
    FileBarChart,
    History,
    LayoutDashboard,
    LibraryBig,
    Menu,
    Pill,
    ReceiptText,
    Search,
    Settings,
    Shield,
    ShoppingCart,
    SlidersHorizontal,
    Truck,
    Users,
    X,
} from 'lucide-vue-next';

const page = usePage();
const mobileSidebarOpen = ref(false);
const collapsedGroups = ref({});

const iconMap = {
    Activity,
    ArchiveX,
    ArrowLeftRight,
    ClipboardList,
    Database,
    FileBarChart,
    History,
    LayoutDashboard,
    LibraryBig,
    Pill,
    ReceiptText,
    Settings,
    Shield,
    ShoppingCart,
    SlidersHorizontal,
    Truck,
    Users,
};

const navigationGroups = computed(() => page.props.navigationGroups ?? []);
const breadcrumbs = computed(() => page.props.breadcrumbs ?? []);
const currentPage = computed(() => page.props.currentPage ?? { title: 'Dashboard' });
const user = computed(() => page.props.auth.user);
const roleLabel = computed(() => ({
    super_admin: 'Super Admin',
    admin: 'Admin',
    employee: 'Karyawan',
}[user.value?.role] ?? user.value?.role));

const isCurrent = (item) => route().current(item.route);
const groupHasActiveItem = (group) => group.items.some((item) => isCurrent(item));
const isGroupOpen = (group) => collapsedGroups.value[group.key] !== true || groupHasActiveItem(group);
const toggleGroup = (group) => {
    collapsedGroups.value = {
        ...collapsedGroups.value,
        [group.key]: isGroupOpen(group),
    };
};
const iconFor = (name) => iconMap[name] ?? LayoutDashboard;
const closeMobileSidebar = () => {
    mobileSidebarOpen.value = false;
};
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-900">
        <div
            v-if="mobileSidebarOpen"
            class="fixed inset-0 z-40 bg-slate-950/40 lg:hidden"
            @click="closeMobileSidebar"
        />

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-emerald-100 bg-white transition-transform duration-200 lg:translate-x-0"
            :class="mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 items-center justify-between border-b border-emerald-100 px-5">
                <Link :href="route('dashboard')" class="flex items-center gap-3" @click="closeMobileSidebar">
                    <ApplicationLogo class="h-9 w-9 fill-current text-emerald-600" />
                    <span class="leading-tight">
                        <span class="block text-sm font-semibold text-slate-950">Inventaris</span>
                        <span class="block text-xs font-medium text-emerald-700">Toko Obat</span>
                    </span>
                </Link>

                <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-md text-slate-500 hover:bg-emerald-50 hover:text-emerald-700 lg:hidden"
                    aria-label="Tutup menu"
                    @click="closeMobileSidebar"
                >
                    <X class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-4">
                <div v-for="group in navigationGroups" :key="group.key" class="mb-3">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-xs font-semibold uppercase text-slate-500 hover:bg-emerald-50 hover:text-emerald-700"
                        :aria-expanded="isGroupOpen(group)"
                        @click="toggleGroup(group)"
                    >
                        <span class="flex min-w-0 items-center gap-2">
                            <component :is="iconFor(group.icon)" class="h-4 w-4 shrink-0" />
                            <span class="truncate">{{ group.label }}</span>
                        </span>
                        <ChevronDown
                            class="h-4 w-4 shrink-0 transition-transform"
                            :class="{ '-rotate-90': !isGroupOpen(group) }"
                        />
                    </button>

                    <div v-show="isGroupOpen(group)" class="mt-1 space-y-1">
                        <Link
                            v-for="item in group.items"
                            :key="`${group.key}-${item.route}`"
                            :href="item.href"
                            class="flex min-h-10 items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition"
                            :class="isCurrent(item)
                                ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-100'
                                : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950'"
                            @click="closeMobileSidebar"
                        >
                            <component :is="iconFor(item.icon)" class="h-4 w-4 shrink-0" />
                            <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                        </Link>
                    </div>
                </div>
            </nav>

            <div class="border-t border-emerald-100 p-4">
                <div class="rounded-md bg-emerald-50 p-3">
                    <div class="text-sm font-semibold text-slate-950">{{ user.name }}</div>
                    <div class="mt-0.5 truncate text-xs text-slate-600">{{ user.email }}</div>
                    <div class="mt-2 inline-flex rounded-md bg-white px-2 py-1 text-xs font-semibold text-emerald-700">
                        {{ roleLabel }}
                    </div>
                </div>
            </div>
        </aside>

        <div class="lg:pl-72">
            <header class="sticky top-0 z-30 border-b border-emerald-100 bg-white/95 backdrop-blur">
                <div class="flex min-h-16 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <div class="flex min-w-0 items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 lg:hidden"
                            aria-label="Buka menu"
                            @click="mobileSidebarOpen = true"
                        >
                            <Menu class="h-5 w-5" />
                        </button>

                        <div class="min-w-0">
                            <nav v-if="breadcrumbs.length" aria-label="Breadcrumb" class="mb-1 flex items-center gap-2 text-xs text-slate-500">
                                <template v-for="(crumb, index) in breadcrumbs" :key="`${crumb.label}-${index}`">
                                    <Link
                                        v-if="crumb.href"
                                        :href="crumb.href"
                                        class="font-medium text-slate-500 hover:text-emerald-700"
                                    >
                                        {{ crumb.label }}
                                    </Link>
                                    <span v-else class="font-medium text-slate-700">{{ crumb.label }}</span>
                                    <span v-if="index < breadcrumbs.length - 1" class="text-slate-300">/</span>
                                </template>
                            </nav>

                            <h1 class="truncate text-lg font-semibold text-slate-950 sm:text-xl">
                                <slot name="title">{{ currentPage.title }}</slot>
                            </h1>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="hidden min-w-64 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 md:flex">
                            <Search class="h-4 w-4 shrink-0" />
                            <span class="truncate">Cari obat, batch, atau transaksi</span>
                        </div>

                        <Dropdown align="right" width="48" contentClasses="py-1 bg-white">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="flex items-center gap-3 rounded-md border border-slate-200 bg-white px-3 py-2 text-left shadow-sm hover:border-emerald-200 hover:bg-emerald-50"
                                >
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-emerald-600 text-sm font-semibold text-white">
                                        {{ user.name.charAt(0) }}
                                    </span>
                                    <span class="hidden leading-tight sm:block">
                                        <span class="block text-sm font-semibold text-slate-950">{{ user.name }}</span>
                                        <span class="block text-xs text-slate-500">{{ roleLabel }}</span>
                                    </span>
                                    <ChevronDown class="h-4 w-4 text-slate-400" />
                                </button>
                            </template>

                            <template #content>
                                <DropdownLink :href="route('profile.edit')">Profil</DropdownLink>
                                <DropdownLink :href="route('logout')" method="post" as="button">
                                    Logout
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>
                </div>
            </header>

            <main class="px-4 py-6 sm:px-6 lg:px-8">
                <div class="mx-auto w-full max-w-7xl">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
