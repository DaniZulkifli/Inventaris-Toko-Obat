<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { Head, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArchiveX,
    Boxes,
    CalendarClock,
    ClipboardList,
    FileClock,
    ShoppingCart,
    WalletCards,
} from 'lucide-vue-next';

const page = usePage();

const currentPage = computed(() => page.props.currentPage ?? { title: 'Dasbor', route: 'dashboard' });
const dashboard = computed(() => page.props.dashboard ?? null);
const isDashboard = computed(() => currentPage.value.route === 'dashboard' && dashboard.value);
const sections = computed(() => dashboard.value?.sections ?? {});

const cardIcons = {
    total_active_medicines: Boxes,
    low_stock: AlertTriangle,
    out_of_stock: ArchiveX,
    near_expiry: CalendarClock,
    expired_batches: FileClock,
    sales_today: ShoppingCart,
    sales_today_by_user: ShoppingCart,
    purchases_this_month: ClipboardList,
    inventory_value: WalletCards,
};

const cardTones = {
    total_active_medicines: 'bg-emerald-50 text-emerald-700',
    low_stock: 'bg-amber-50 text-amber-700',
    out_of_stock: 'bg-slate-100 text-slate-700',
    near_expiry: 'bg-violet-50 text-violet-700',
    expired_batches: 'bg-red-50 text-red-700',
    sales_today: 'bg-sky-50 text-sky-700',
    sales_today_by_user: 'bg-sky-50 text-sky-700',
    purchases_this_month: 'bg-indigo-50 text-indigo-700',
    inventory_value: 'bg-emerald-50 text-emerald-700',
};

const formatNumber = (value) => new Intl.NumberFormat('id-ID').format(Number(value ?? 0));
const formatCurrency = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
}).format(Number(value ?? 0));
const formatQuantity = (value) => new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 3,
}).format(Number(value ?? 0));
const formatDate = (value) => value
    ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value))
    : '-';
const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value))
    : '-';
const cardValue = (card) => card.format === 'currency' ? formatCurrency(card.value) : formatNumber(card.value);

const placeholderColumns = [
    { key: 'module', label: 'Modul', sortable: true },
    { key: 'status', label: 'Status' },
    { key: 'updated_at', label: 'Update' },
];
</script>

<template>
    <Head :title="currentPage.title" />

    <AuthenticatedLayout>
        <div v-if="isDashboard" class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="card in dashboard.cards"
                    :key="card.key"
                    class="rounded-md border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-500">{{ card.label }}</p>
                            <p class="mt-2 truncate text-3xl font-semibold text-slate-950">{{ cardValue(card) }}</p>
                        </div>
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md" :class="cardTones[card.key]">
                            <component :is="cardIcons[card.key] ?? Activity" class="h-5 w-5" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid items-start gap-6 xl:grid-cols-[1.35fr_0.65fr]">
                <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-slate-950">
                            {{ dashboard.role === 'employee' ? 'Stok Penting' : 'Batch Hampir Kedaluwarsa' }}
                        </h3>
                        <StatusBadge status="low_stock" label="Prioritas" />
                    </div>

                    <div v-if="dashboard.role === 'employee'" class="max-h-[22rem] space-y-3 overflow-y-auto pr-1">
                        <div
                            v-for="item in sections.important_stock"
                            :key="item.id"
                            class="flex items-center justify-between gap-4 rounded-md border border-slate-100 p-4"
                        >
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-slate-950">{{ item.name }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ item.code }} · {{ formatQuantity(item.saleable_stock) }} stok jual</div>
                            </div>
                            <StatusBadge :status="item.status" :label="item.status === 'out_of_stock' ? 'Habis' : 'Menipis'" />
                        </div>
                        <div v-if="!sections.important_stock?.length" class="rounded-md border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500">
                            Tidak ada stok penting.
                        </div>
                    </div>

                    <div v-else class="max-h-[22rem] space-y-3 overflow-y-auto pr-1">
                        <div
                            v-for="batch in sections.near_expiry_batches"
                            :key="batch.id"
                            class="flex items-center justify-between gap-4 rounded-md border border-slate-100 p-4"
                        >
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-slate-950">{{ batch.medicine }}</div>
                                <div class="mt-1 text-sm text-slate-500">
                                    {{ batch.batch_number }} · {{ formatQuantity(batch.current_stock) }} stok
                                </div>
                            </div>
                            <div class="text-right text-sm font-semibold text-amber-700">{{ formatDate(batch.expiry_date) }}</div>
                        </div>
                        <div v-if="!sections.near_expiry_batches?.length" class="rounded-md border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500">
                            Tidak ada batch hampir kedaluwarsa.
                        </div>
                    </div>
                </section>

                <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-base font-semibold text-slate-950">
                        {{ dashboard.role === 'super_admin' ? 'Aktivitas Terbaru' : dashboard.role === 'admin' ? 'Draft Adjustment' : 'Penjualan Saya' }}
                    </h3>

                    <div v-if="dashboard.role === 'super_admin'" class="mt-4 max-h-[22rem] space-y-3 overflow-y-auto pr-1">
                        <div v-for="activity in sections.latest_activity" :key="activity.id" class="rounded-md border border-slate-100 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-semibold text-slate-950">{{ activity.module }}</span>
                                <span class="text-xs text-slate-500">{{ activity.user }}</span>
                            </div>
                            <p class="mt-1 line-clamp-2 text-sm text-slate-600">{{ activity.description }}</p>
                        </div>
                        <div v-if="!sections.latest_activity?.length" class="rounded-md border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500">
                            Belum ada aktivitas.
                        </div>
                    </div>

                    <div v-else-if="dashboard.role === 'admin'" class="mt-4 max-h-[22rem] space-y-3 overflow-y-auto pr-1">
                        <div v-for="adjustment in sections.draft_adjustments" :key="adjustment.id" class="rounded-md border border-slate-100 p-3">
                            <div class="font-semibold text-slate-950">{{ adjustment.code }}</div>
                            <div class="mt-1 text-sm text-slate-500">{{ adjustment.creator }} · {{ formatDate(adjustment.adjustment_date) }}</div>
                        </div>
                        <div v-if="!sections.draft_adjustments?.length" class="rounded-md border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500">
                            Tidak ada draft adjustment.
                        </div>
                    </div>

                    <div v-else class="mt-4 rounded-md bg-emerald-50 p-4">
                        <div class="text-sm font-medium text-emerald-700">Total transaksi</div>
                        <div class="mt-2 text-3xl font-semibold text-emerald-950">{{ formatNumber(sections.sales_today?.count) }}</div>
                        <div class="mt-1 text-sm text-emerald-800">{{ formatCurrency(sections.sales_today?.total_amount) }}</div>
                    </div>
                </section>
            </div>

            <div v-if="dashboard.role === 'admin'" class="grid items-start gap-6 xl:grid-cols-2">
                <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-base font-semibold text-slate-950">Pesanan Pembelian Terbaru</h3>
                    <div class="mt-4 max-h-[22rem] space-y-3 overflow-y-auto pr-1">
                        <div v-for="purchaseOrder in sections.latest_purchase_orders" :key="purchaseOrder.id" class="flex items-center justify-between gap-4 rounded-md border border-slate-100 p-3">
                            <div>
                                <div class="font-semibold text-slate-950">{{ purchaseOrder.code }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ purchaseOrder.supplier }}</div>
                            </div>
                            <StatusBadge :status="purchaseOrder.status" />
                        </div>
                    </div>
                </section>

                <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-base font-semibold text-slate-950">Pemakaian Stok Terbaru</h3>
                    <div class="mt-4 max-h-[22rem] space-y-3 overflow-y-auto pr-1">
                        <div v-for="usage in sections.latest_stock_usages" :key="usage.id" class="flex items-center justify-between gap-4 rounded-md border border-slate-100 p-3">
                            <div>
                                <div class="font-semibold text-slate-950">{{ usage.code }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ usage.creator }} · {{ formatDate(usage.usage_date) }}</div>
                            </div>
                            <StatusBadge :status="usage.status" />
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <div v-else class="space-y-5">
            <DataTable
                :columns="placeholderColumns"
                :rows="[]"
                empty-title="Data belum tersedia"
                empty-description="Data akan muncul setelah modul ini terisi."
            />
        </div>
    </AuthenticatedLayout>
</template>
