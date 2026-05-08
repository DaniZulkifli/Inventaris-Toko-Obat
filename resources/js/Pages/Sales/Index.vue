<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CurrencyInput from '@/Components/UI/CurrencyInput.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DateInput from '@/Components/UI/DateInput.vue';
import DetailModal from '@/Components/UI/DetailModal.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import NumberInput from '@/Components/UI/NumberInput.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import TextareaInput from '@/Components/UI/TextareaInput.vue';
import UiButton from '@/Components/UI/UiButton.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Eye, Plus, RotateCcw, Search, X } from 'lucide-vue-next';

const props = defineProps({
    sales: { type: Object, required: true },
    filters: { type: Object, required: true },
    historyScope: { type: String, default: 'mine' },
    canCreate: { type: Boolean, default: true },
    options: { type: Object, required: true },
});

const blankItem = () => ({
    search: '',
    medicine_id: '',
    medicine_batch_id: '',
    quantity: 1,
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

const form = useForm({
    customer_name: 'Pelanggan Umum',
    payment_method: 'cash',
    discount: 0,
    amount_paid: 0,
    notes: '',
    items: [blankItem()],
});

const medicineMap = computed(() => new Map(props.options.medicines.map((item) => [String(item.id), item])));
const batchMap = computed(() => new Map(props.options.saleable_batches.map((item) => [String(item.id), item])));
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
const itemError = (index, key) => form.errors[`items.${index}.${key}`] ?? '';
const selectedMedicine = (item) => medicineMap.value.get(String(item.medicine_id));
const selectedBatch = (item) => batchMap.value.get(String(item.medicine_batch_id));
const medicineOptions = (item) => {
    const query = String(item.search ?? '').toLowerCase();
    const source = props.options.medicines;

    return source
        .filter((medicine) => {
            if (!query) {
                return true;
            }

            return [medicine.code, medicine.barcode, medicine.name, medicine.label]
                .filter(Boolean)
                .some((value) => String(value).toLowerCase().includes(query));
        })
        .slice(0, 40)
        .map((medicine) => ({
            value: medicine.id,
            label: `${medicine.label} - stok ${formatQuantity(medicine.saleable_stock)}`,
        }));
};
const batchOptions = (item) => props.options.saleable_batches
    .filter((batch) => String(batch.medicine_id) === String(item.medicine_id))
    .map((batch) => ({
        value: batch.id,
        label: batch.label,
    }));
const unitPriceFor = (item) => Number(selectedBatch(item)?.unit_price ?? selectedMedicine(item)?.selling_price ?? 0);
const batchLabelFor = (item) => selectedBatch(item)?.batch_number ?? 'FEFO otomatis';
const lineSubtotal = (item) => Number(item.quantity || 0) * unitPriceFor(item);
const subtotal = computed(() => form.items.reduce((total, item) => total + lineSubtotal(item), 0));
const total = computed(() => Math.max(subtotal.value - Number(form.discount || 0), 0));
const amountPaidPreview = computed(() => form.payment_method === 'cash' ? Number(form.amount_paid || 0) : total.value);
const changePreview = computed(() => form.payment_method === 'cash' ? Math.max(amountPaidPreview.value - total.value, 0) : 0);

const resetForm = () => {
    form.clearErrors();
    form.customer_name = 'Pelanggan Umum';
    form.payment_method = 'cash';
    form.discount = 0;
    form.amount_paid = 0;
    form.notes = '';
    form.items = [blankItem()];
};

const addItem = () => {
    form.items.push(blankItem());
};

const removeItem = (index) => {
    if (form.items.length === 1) {
        return;
    }

    form.items.splice(index, 1);
};

const selectMedicine = (item, medicineId) => {
    item.medicine_id = medicineId;
    item.medicine_batch_id = '';
    const medicine = selectedMedicine(item);

    if (medicine) {
        item.search = [medicine.code, medicine.barcode, medicine.name].filter(Boolean).join(' ');
    }
};

const submit = () => {
    form.post(route('sales.store'), {
        preserveScroll: true,
        onSuccess: () => resetForm(),
    });
};

const applyFilters = () => {
    router.get(
        props.historyScope === 'mine' && !props.canCreate ? route('sales.my-history') : route('sales.index'),
        filterForm.value,
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const openDetail = (sale) => {
    detailTarget.value = sale;
    showDetailModal.value = true;
};
</script>

<template>
    <Head title="Penjualan" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-950">{{ canCreate ? 'Penjualan' : 'Riwayat Penjualan Saya' }}</h2>
                    <p class="mt-1 text-sm text-slate-500">Transaksi tersimpan sebagai final completed</p>
                </div>
                <UiButton v-if="canCreate" variant="secondary" @click="resetForm">
                    <RotateCcw class="h-4 w-4" />
                    Reset Form
                </UiButton>
            </div>

            <section v-if="canCreate" class="overflow-hidden rounded-md border border-slate-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-slate-950">Transaksi Baru</h3>
                        <p v-if="form.errors.stock" class="mt-1 text-sm font-medium text-red-600">{{ form.errors.stock }}</p>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-right text-sm">
                        <div>
                            <div class="text-xs text-slate-500">Subtotal</div>
                            <div class="font-semibold text-slate-950">{{ formatCurrency(subtotal) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500">Diskon</div>
                            <div class="font-semibold text-slate-950">{{ formatCurrency(form.discount) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500">Total</div>
                            <div class="font-semibold text-emerald-700">{{ formatCurrency(total) }}</div>
                        </div>
                    </div>
                </div>

                <form class="space-y-5 p-4" @submit.prevent="submit">
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_12rem_12rem_12rem]">
                        <FormInput id="customer_name" v-model="form.customer_name" label="Customer" :error="form.errors.customer_name" />
                        <SelectInput id="payment_method" v-model="form.payment_method" label="Metode Bayar" :options="options.payment_methods" required :error="form.errors.payment_method" />
                        <CurrencyInput id="discount" v-model="form.discount" label="Diskon" :error="form.errors.discount" />
                        <CurrencyInput v-if="form.payment_method === 'cash'" id="amount_paid" v-model="form.amount_paid" label="Uang Diterima" required :error="form.errors.amount_paid" />
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <h4 class="text-sm font-semibold text-slate-800">Item Penjualan</h4>
                            <UiButton size="sm" variant="secondary" @click="addItem">
                                <Plus class="h-4 w-4" />
                                Tambah Item
                            </UiButton>
                        </div>
                        <p v-if="form.errors.items" class="text-xs font-medium text-red-600">{{ form.errors.items }}</p>

                        <div v-for="(item, index) in form.items" :key="index" class="rounded-md border border-slate-200 p-3">
                            <div class="grid gap-3 xl:grid-cols-[minmax(12rem,1fr)_minmax(14rem,1.4fr)_minmax(12rem,1fr)_8rem_9rem_auto]">
                                <FormInput :id="`sale_item_${index}_search`" v-model="item.search" label="Cari Obat" placeholder="Kode, barcode, nama" />
                                <SelectInput
                                    :id="`sale_item_${index}_medicine`"
                                    :model-value="item.medicine_id"
                                    label="Obat"
                                    :options="medicineOptions(item)"
                                    required
                                    :error="itemError(index, 'medicine_id')"
                                    @update:model-value="selectMedicine(item, $event)"
                                />
                                <SelectInput
                                    :id="`sale_item_${index}_batch`"
                                    v-model="item.medicine_batch_id"
                                    label="Batch Manual"
                                    :options="batchOptions(item)"
                                    placeholder="FEFO otomatis"
                                    :disabled="!item.medicine_id"
                                    :error="itemError(index, 'medicine_batch_id')"
                                />
                                <NumberInput
                                    :id="`sale_item_${index}_quantity`"
                                    v-model="item.quantity"
                                    label="Qty"
                                    required
                                    min="0.001"
                                    step="0.001"
                                    :error="itemError(index, 'quantity')"
                                />
                                <div>
                                    <div class="block text-sm font-semibold text-slate-700">Subtotal</div>
                                    <div class="mt-1 flex min-h-10 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-900">
                                        {{ formatCurrency(lineSubtotal(item)) }}
                                    </div>
                                </div>
                                <div class="flex items-end justify-end">
                                    <IconButton label="Hapus item" variant="danger" :disabled="form.items.length === 1" @click="removeItem(index)">
                                        <X class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-md border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Obat</th>
                                    <th class="px-4 py-3">Batch</th>
                                    <th class="px-4 py-3 text-right">Qty</th>
                                    <th class="px-4 py-3 text-right">Harga</th>
                                    <th class="px-4 py-3 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(item, index) in form.items" :key="`summary_${index}`">
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-slate-950">{{ selectedMedicine(item)?.name ?? '-' }}</div>
                                        <div class="mt-1 text-xs text-slate-500">{{ selectedMedicine(item)?.code ?? '-' }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-slate-700">{{ batchLabelFor(item) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-700">{{ formatQuantity(item.quantity) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-700">{{ formatCurrency(unitPriceFor(item)) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-950">{{ formatCurrency(lineSubtotal(item)) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
                        <TextareaInput id="notes" v-model="form.notes" label="Notes" :error="form.errors.notes" />
                        <div class="rounded-md border border-emerald-100 bg-emerald-50 p-4 text-sm">
                            <div class="flex justify-between gap-4">
                                <span class="text-emerald-800">Total</span>
                                <span class="font-semibold text-emerald-950">{{ formatCurrency(total) }}</span>
                            </div>
                            <div class="mt-2 flex justify-between gap-4">
                                <span class="text-emerald-800">Dibayar</span>
                                <span class="font-semibold text-emerald-950">{{ formatCurrency(amountPaidPreview) }}</span>
                            </div>
                            <div class="mt-2 flex justify-between gap-4">
                                <span class="text-emerald-800">Kembalian</span>
                                <span class="font-semibold text-emerald-950">{{ formatCurrency(changePreview) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-slate-200 pt-4">
                        <UiButton type="submit" :loading="form.processing">
                            <CheckCircle2 class="h-4 w-4" />
                            Simpan Penjualan
                        </UiButton>
                    </div>
                </form>
            </section>

            <DataTable :columns="columns" :rows="sales.data" empty-title="Belum ada penjualan">
                <template #filters>
                    <form class="grid gap-3 xl:grid-cols-[1fr_12rem_12rem_12rem_12rem_auto]" @submit.prevent="applyFilters">
                        <FormInput id="history_search" v-model="filterForm.search" label="Pencarian" placeholder="Invoice, obat, customer" />
                        <SelectInput v-if="historyScope === 'all'" id="cashier_id" v-model="filterForm.cashier_id" label="Kasir" :options="options.cashiers" placeholder="Semua kasir" />
                        <SelectInput id="payment_method_filter" v-model="filterForm.payment_method" label="Metode" :options="options.payment_methods" placeholder="Semua metode" />
                        <DateInput id="date_from" v-model="filterForm.date_from" label="Dari" />
                        <DateInput id="date_to" v-model="filterForm.date_to" label="Sampai" />
                        <div class="flex items-end">
                            <UiButton type="submit" class="w-full">
                                <Search class="h-4 w-4" />
                                Filter
                            </UiButton>
                        </div>
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
                            <StatusBadge :status="row.status" />
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
                        <div class="mt-1"><StatusBadge :status="detailTarget.status" /></div>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-md border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Obat</th>
                                <th class="px-4 py-3">Batch</th>
                                <th class="px-4 py-3">Expiry</th>
                                <th class="px-4 py-3 text-right">Qty</th>
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
