<script setup>
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DateInput from '@/Components/UI/DateInput.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import UiButton from '@/Components/UI/UiButton.vue';
import { useRealtimeFilters } from '@/Composables/useRealtimeFilters';
import { Head } from '@inertiajs/vue3';
import { Download, FileSpreadsheet, FileText, X } from 'lucide-vue-next';

const props = defineProps({
    report: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
});

const exportLoading = ref('');
const toast = ref(null);
const filterForm = ref({ ...props.filters });

const type = computed(() => filterForm.value.jenis_laporan);
const isStockBatch = computed(() => type.value === 'stock');
const isStockLevel = computed(() => ['low_stock', 'out_of_stock'].includes(type.value));
const isExpiry = computed(() => type.value === 'expiry');
const isPurchase = computed(() => type.value === 'purchase');
const isSales = computed(() => type.value === 'sales');
const isMargin = computed(() => type.value === 'simple_margin');
const isMovement = computed(() => type.value === 'stock_movement');
const isSupplier = computed(() => type.value === 'supplier');
const usesTransactionDate = computed(() => ['purchase', 'sales', 'simple_margin', 'stock_movement'].includes(type.value));

const formatNumber = (value) => new Intl.NumberFormat('id-ID').format(Number(value ?? 0));
const formatQuantity = (value) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(Number(value ?? 0));
const formatCurrency = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
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

const formattedValue = (value, format) => {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    if (format === 'currency') {
        return formatCurrency(value);
    }

    if (format === 'quantity') {
        return formatQuantity(value);
    }

    if (format === 'number') {
        return formatNumber(value);
    }

    if (format === 'date') {
        return formatDate(value);
    }

    if (format === 'datetime') {
        return formatDateTime(value);
    }

    return String(value).replaceAll('_', ' ');
};

const summaryValue = (item) => formattedValue(item.value, item.format);

useRealtimeFilters(filterForm, () => route('reports.index'));

watch(
    () => filterForm.value.jenis_laporan,
    () => {
        Object.assign(filterForm.value, {
            date_from: '',
            date_to: '',
            expiry_from: '',
            expiry_to: '',
            category_id: '',
            medicine_id: '',
            supplier_id: '',
            batch_status: '',
            purchase_status: '',
            cashier_id: '',
            payment_method: '',
            batch_id: '',
            movement_type: '',
            created_by: '',
            supplier_status: '',
            supplier_name: '',
        });
    },
);

const filenameFromDisposition = (disposition, fallback) => {
    const match = disposition?.match(/filename="?([^"]+)"?/i);

    return match?.[1] ?? fallback;
};

const showToast = (message, type = 'success') => {
    toast.value = { message, type };
    window.setTimeout(() => {
        toast.value = null;
    }, 3500);
};

const exportReport = async (format) => {
    exportLoading.value = format;

    try {
        const response = await window.axios.get(route('reports.export'), {
            params: { ...filterForm.value, format },
            responseType: 'blob',
        });
        const fallback = `laporan-${filterForm.value.jenis_laporan}.${format}`;
        const filename = filenameFromDisposition(response.headers['content-disposition'], fallback);
        const url = window.URL.createObjectURL(new Blob([response.data], { type: response.headers['content-type'] }));
        const link = document.createElement('a');

        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
        showToast('Export laporan berhasil diproses.');
    } catch (error) {
        showToast('Export gagal diproses. Periksa filter lalu coba lagi.', 'error');
    } finally {
        exportLoading.value = '';
    }
};
</script>

<template>
    <Head title="Laporan" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div class="flex justify-end">
                <div class="flex flex-wrap gap-2">
                    <UiButton variant="secondary" :loading="exportLoading === 'pdf'" :disabled="exportLoading !== ''" @click="exportReport('pdf')">
                        <FileText class="h-4 w-4" />
                        PDF
                    </UiButton>
                    <UiButton :loading="exportLoading === 'xlsx'" :disabled="exportLoading !== ''" @click="exportReport('xlsx')">
                        <FileSpreadsheet class="h-4 w-4" />
                        Excel
                    </UiButton>
                </div>
            </div>

            <div
                v-if="toast"
                class="fixed right-4 top-4 z-50 flex max-w-sm items-start gap-3 rounded-md border bg-white px-4 py-3 text-sm shadow-lg"
                :class="toast.type === 'error' ? 'border-red-200 text-red-700' : 'border-emerald-200 text-emerald-700'"
            >
                <Download v-if="toast.type !== 'error'" class="mt-0.5 h-4 w-4 shrink-0" />
                <X v-else class="mt-0.5 h-4 w-4 shrink-0" />
                <span>{{ toast.message }}</span>
            </div>

            <section class="rounded-md border border-slate-200 bg-white">
                <form class="space-y-4 p-4" @submit.prevent>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <SelectInput
                            id="jenis_laporan"
                            v-model="filterForm.jenis_laporan"
                            label="Jenis Laporan"
                            :options="options.report_types"
                            required
                        />
                        <DateInput v-if="usesTransactionDate" id="date_from" v-model="filterForm.date_from" label="Dari Tanggal" required />
                        <DateInput v-if="usesTransactionDate" id="date_to" v-model="filterForm.date_to" label="Sampai Tanggal" required />
                        <DateInput v-if="isExpiry" id="expiry_from" v-model="filterForm.expiry_from" label="Kedaluwarsa Dari" required />
                        <DateInput v-if="isExpiry" id="expiry_to" v-model="filterForm.expiry_to" label="Kedaluwarsa Sampai" required />
                    </div>

                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <SelectInput
                            v-if="isStockBatch || isStockLevel || isExpiry || isMargin"
                            id="category_id"
                            v-model="filterForm.category_id"
                            label="Kategori"
                            :options="options.categories"
                            placeholder="Semua kategori"
                        />
                        <SelectInput
                            v-if="!isSupplier"
                            id="medicine_id"
                            v-model="filterForm.medicine_id"
                            label="Obat"
                            :options="options.medicines"
                            placeholder="Semua obat"
                        />
                        <SelectInput
                            v-if="isStockBatch || isStockLevel || isExpiry || isPurchase"
                            id="supplier_id"
                            v-model="filterForm.supplier_id"
                            label="Supplier"
                            :options="options.suppliers"
                            placeholder="Semua supplier"
                        />
                        <SelectInput
                            v-if="isStockBatch || isExpiry"
                            id="batch_status"
                            v-model="filterForm.batch_status"
                            label="Status Batch"
                            :options="options.batch_statuses"
                            placeholder="Semua status"
                        />
                        <SelectInput
                            v-if="isPurchase"
                            id="purchase_status"
                            v-model="filterForm.purchase_status"
                            label="Status PO"
                            :options="options.purchase_statuses"
                            placeholder="Semua status"
                        />
                        <SelectInput
                            v-if="isSales || isMargin"
                            id="cashier_id"
                            v-model="filterForm.cashier_id"
                            label="Kasir"
                            :options="options.cashiers"
                            placeholder="Semua kasir"
                        />
                        <SelectInput
                            v-if="isSales"
                            id="payment_method"
                            v-model="filterForm.payment_method"
                            label="Pembayaran"
                            :options="options.payment_methods"
                            placeholder="Semua metode"
                        />
                        <SelectInput
                            v-if="isMovement"
                            id="batch_id"
                            v-model="filterForm.batch_id"
                            label="Batch"
                            :options="options.batches"
                            placeholder="Semua batch"
                        />
                        <SelectInput
                            v-if="isMovement"
                            id="movement_type"
                            v-model="filterForm.movement_type"
                            label="Tipe Mutasi"
                            :options="options.movement_types"
                            placeholder="Semua tipe"
                        />
                        <SelectInput
                            v-if="isMovement"
                            id="created_by"
                            v-model="filterForm.created_by"
                            label="Pengguna Pembuat"
                            :options="options.users"
                            placeholder="Semua user"
                        />
                        <SelectInput
                            v-if="isSupplier"
                            id="supplier_status"
                            v-model="filterForm.supplier_status"
                            label="Status Supplier"
                            :options="options.supplier_statuses"
                            placeholder="Semua status"
                        />
                        <FormInput
                            v-if="isSupplier"
                            id="supplier_name"
                            v-model="filterForm.supplier_name"
                            label="Nama Supplier"
                            placeholder="Cari supplier"
                        />
                    </div>

                </form>
            </section>

            <div class="grid gap-3 md:grid-cols-3">
                <div v-for="item in report.summary" :key="item.label" class="rounded-md border border-slate-200 bg-white px-4 py-3">
                    <div class="text-xs font-semibold uppercase text-slate-500">{{ item.label }}</div>
                    <div class="mt-1 text-lg font-semibold text-slate-950">{{ summaryValue(item) }}</div>
                </div>
            </div>

            <DataTable
                :columns="report.columns"
                :rows="report.rows.data"
                :empty-title="`Belum ada data ${report.title}`"
                empty-description="Data laporan akan tampil sesuai jenis laporan dan filter aktif."
            >
                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'medicine'">
                        <div class="font-semibold text-slate-950">{{ value ?? '-' }}</div>
                        <div v-if="row.medicine_code" class="mt-1 text-xs text-slate-500">{{ row.medicine_code }}</div>
                    </template>
                    <template v-else-if="column.format === 'badge'">
                        <StatusBadge :status="String(value ?? 'inactive')" :label="formattedValue(value, column.format)" />
                    </template>
                    <template v-else>
                        {{ formattedValue(value, column.format) }}
                    </template>
                </template>

                <template #pagination>
                    <Pagination :meta="report.rows" />
                </template>
            </DataTable>
        </div>
    </AuthenticatedLayout>
</template>
