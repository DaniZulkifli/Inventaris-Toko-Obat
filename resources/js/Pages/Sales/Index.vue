<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DateInput from '@/Components/UI/DateInput.vue';
import DetailModal from '@/Components/UI/DetailModal.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { useRealtimeFilters } from '@/Composables/useRealtimeFilters';
import { Head, Link } from '@inertiajs/vue3';
import { Eye, Plus } from 'lucide-vue-next';

const props = defineProps({
    sales: { type: Object, required: true },
    filters: { type: Object, required: true },
    historyScope: { type: String, default: 'mine' },
    canCreate: { type: Boolean, default: true },
    options: { type: Object, required: true },
});

const detailTarget = ref(null);
const showDetailModal = ref(false);
const filterForm = ref({
    search: props.filters.search ?? '',
    payment_method: props.filters.payment_method ?? '',
    cashier_id: props.filters.cashier_id ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});

const columns = [
    { key: 'invoice_number', label: 'Invoice', sortable: true },
    { key: 'cashier', label: 'Kasir' },
    { key: 'sale_date', label: 'Tanggal' },
    { key: 'payment_method', label: 'Metode' },
    { key: 'total_amount', label: 'Total', align: 'right' },
    { key: 'actions', label: '', align: 'right' },
];

const formatCurrency = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
}).format(Number(value ?? 0));
const formatQuantity = (value) => new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 3,
}).format(Number(value ?? 0));
const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
    : '-';
const paymentLabel = (value) => props.options.payment_methods.find((item) => item.value === value)?.label ?? value;
const statusLabel = (value) => ({
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
}[value] ?? String(value ?? '-').replaceAll('_', ' '));

useRealtimeFilters(
    filterForm,
    () => props.historyScope === 'mine' && !props.canCreate ? route('sales.my-history') : route('sales.index'),
);

const openDetail = (sale) => {
    detailTarget.value = sale;
    showDetailModal.value = true;
};
</script>

<template>
    <Head title="Penjualan" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div v-if="canCreate" class="flex justify-end">
                <Link
                    :href="route('sales.create')"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-emerald-600 bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                >
                    <Plus class="h-4 w-4" />
                    Transaksi Baru
                </Link>
            </div>

            <DataTable :columns="columns" :rows="sales.data" empty-title="Belum ada penjualan">
                <template #filters>
                    <form class="grid gap-3 xl:grid-cols-[1fr_12rem_12rem_12rem_12rem]" @submit.prevent>
                        <FormInput id="history_search" v-model="filterForm.search" label="Pencarian" placeholder="Invoice, obat, pelanggan" />
                        <SelectInput v-if="historyScope === 'all'" id="cashier_id" v-model="filterForm.cashier_id" label="Kasir" :options="options.cashiers" placeholder="Semua kasir" />
                        <SelectInput id="payment_method_filter" v-model="filterForm.payment_method" label="Metode" :options="options.payment_methods" placeholder="Semua metode" />
                        <DateInput id="date_from" v-model="filterForm.date_from" label="Dari" />
                        <DateInput id="date_to" v-model="filterForm.date_to" label="Sampai" />
                    </form>
                </template>

                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'invoice_number'">
                        <div class="font-semibold text-slate-950">{{ row.invoice_number }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.customer_name ?? 'Pelanggan Umum' }}</div>
                    </template>
                    <template v-else-if="column.key === 'sale_date'">{{ formatDateTime(value) }}</template>
                    <template v-else-if="column.key === 'payment_method'">
                        <div class="flex items-center gap-2">
                            <StatusBadge status="completed" :label="paymentLabel(value)" />
                            <StatusBadge :status="row.status" :label="statusLabel(row.status)" />
                        </div>
                    </template>
                    <template v-else-if="column.key === 'total_amount'">
                        <div class="font-semibold text-slate-950">{{ formatCurrency(value) }}</div>
                        <div class="mt-1 text-xs text-slate-500">Kembali {{ formatCurrency(row.change_amount) }}</div>
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <div class="flex justify-end gap-2">
                            <IconButton label="Detail penjualan" @click="openDetail(row)">
                                <Eye class="h-4 w-4" />
                            </IconButton>
                        </div>
                    </template>
                    <template v-else>{{ value }}</template>
                </template>

                <template #pagination>
                    <Pagination :meta="sales" />
                </template>
            </DataTable>
        </div>

        <DetailModal :show="showDetailModal" :title="detailTarget?.invoice_number ?? 'Detail Penjualan'" max-width="4xl" @close="showDetailModal = false">
            <div v-if="detailTarget" class="space-y-5">
                <div class="grid gap-3 text-sm md:grid-cols-4">
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Kasir</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ detailTarget.cashier }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Tanggal</div>
                        <div class="mt-1 text-slate-700">{{ formatDateTime(detailTarget.sale_date) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Metode</div>
                        <div class="mt-1 text-slate-700">{{ paymentLabel(detailTarget.payment_method) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Status</div>
                        <div class="mt-1"><StatusBadge :status="detailTarget.status" :label="statusLabel(detailTarget.status)" /></div>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-md border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Obat</th>
                                <th class="px-4 py-3">Batch</th>
                                <th class="px-4 py-3">Kedaluwarsa</th>
                                <th class="px-4 py-3 text-right">Jumlah</th>
                                <th class="px-4 py-3 text-right">Harga</th>
                                <th class="px-4 py-3 text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="item in detailTarget.items" :key="item.id">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-950">{{ item.medicine_name_snapshot }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ item.medicine_code_snapshot }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ item.batch_number_snapshot }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ item.expiry_date_snapshot ?? '-' }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ formatQuantity(item.quantity) }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ formatCurrency(item.unit_price_snapshot) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-950">{{ formatCurrency(item.subtotal) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="grid gap-3 text-sm md:grid-cols-4">
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Subtotal</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ formatCurrency(detailTarget.subtotal) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Diskon</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ formatCurrency(detailTarget.discount) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Dibayar</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ formatCurrency(detailTarget.amount_paid) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Kembalian</div>
                        <div class="mt-1 font-semibold text-emerald-700">{{ formatCurrency(detailTarget.change_amount) }}</div>
                    </div>
                </div>
            </div>
        </DetailModal>
    </AuthenticatedLayout>
</template>
