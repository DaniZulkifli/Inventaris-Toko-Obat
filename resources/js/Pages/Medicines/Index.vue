<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DeleteConfirmationModal from '@/Components/UI/DeleteConfirmationModal.vue';
import FloatingFormModal from '@/Components/UI/FloatingFormModal.vue';
import BarcodeInput from '@/Components/UI/BarcodeInput.vue';
import CurrencyInput from '@/Components/UI/CurrencyInput.vue';
import DateInput from '@/Components/UI/DateInput.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import NumberInput from '@/Components/UI/NumberInput.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import TextareaInput from '@/Components/UI/TextareaInput.vue';
import UiButton from '@/Components/UI/UiButton.vue';
import { useRealtimeFilters } from '@/Composables/useRealtimeFilters';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    medicines: { type: Object, required: true },
    batches: { type: Object, default: null },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});

const activeTab = ref(new URLSearchParams(window.location.search).get('tab') || 'medicines');
const medicineMode = ref('create');
const batchMode = ref('create');
const showMedicineModal = ref(false);
const showBatchModal = ref(false);
const showDeleteModal = ref(false);
const deleteProcessing = ref(false);
const deleteTarget = ref(null);
const deleteType = ref('medicine');
const selectedMedicine = ref(null);
const selectedBatch = ref(null);

const medicineFilters = ref({
    search: props.filters.search ?? '',
    classification: props.filters.classification ?? '',
    status: props.filters.status ?? '',
});
const batchFilters = ref({
    batch_search: props.filters.batch_search ?? '',
    batch_status: props.filters.batch_status ?? '',
    supplier_id: props.filters.supplier_id ?? '',
    medicine_id: props.filters.medicine_id ?? '',
});

const booleanOptions = [
    { value: 1, label: 'Ya' },
    { value: 0, label: 'Tidak' },
];
const activeOptions = [
    { value: 1, label: 'Aktif' },
    { value: 0, label: 'Nonaktif' },
];
const medicineStatusOptions = [
    { value: 'active', label: 'Aktif' },
    { value: 'inactive', label: 'Nonaktif' },
];

const medicineOptions = computed(() => (props.options.medicines ?? props.medicines.data).map((medicine) => ({
    value: medicine.id,
    label: medicine.label ?? `${medicine.code} - ${medicine.name}`,
})));
const categoryOptions = computed(() => props.options.categories.map((item) => ({ value: item.id, label: item.name })));
const unitOptions = computed(() => props.options.units.map((item) => ({ value: item.id, label: `${item.name} (${item.symbol})` })));
const dosageFormOptions = computed(() => props.options.dosage_forms.map((item) => ({ value: item.id, label: item.name })));
const supplierOptions = computed(() => props.options.suppliers.map((item) => ({ value: item.id, label: `${item.name}${item.is_active ? '' : ' (nonaktif)'}` })));
const activeSupplierOptions = computed(() => props.options.active_suppliers.map((item) => ({ value: item.id, label: item.name })));

const medicineForm = useForm({
    medicine_category_id: '',
    unit_id: '',
    dosage_form_id: '',
    code: '',
    barcode: '',
    name: '',
    generic_name: '',
    manufacturer: '',
    registration_number: '',
    active_ingredient: '',
    strength: '',
    classification: 'obat_bebas',
    requires_prescription: 0,
    default_purchase_price: 0,
    selling_price: 0,
    minimum_stock: 0,
    reorder_level: 0,
    storage_instruction: '',
    image_path: null,
    is_active: 1,
});

const batchForm = useForm({
    medicine_id: '',
    supplier_id: '',
    batch_number: '',
    expiry_date: '',
    purchase_price: 0,
    selling_price: '',
    initial_stock: 0,
    received_date: '',
    status: 'available',
    notes: '',
});

const medicineColumns = [
    { key: 'name', label: 'Obat', sortable: true },
    { key: 'classification', label: 'Klasifikasi' },
    { key: 'selling_price', label: 'Harga Jual' },
    { key: 'minimum_stock', label: 'Stok Minimum' },
    { key: 'is_active', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];
const batchColumns = [
    { key: 'batch_number', label: 'Batch', sortable: true },
    { key: 'medicine', label: 'Obat' },
    { key: 'expiry_date', label: 'Kedaluwarsa' },
    { key: 'current_stock', label: 'Stok' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];

const formatCurrency = (value) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value ?? 0));
const formatQuantity = (value) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(Number(value ?? 0));
const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value)) : '-';
const labelFor = (items, value) => items.find((item) => item.value === value)?.label ?? value;

const openCreateMedicine = () => {
    medicineMode.value = 'create';
    selectedMedicine.value = null;
    medicineForm.reset();
    medicineForm.clearErrors();
    medicineForm.classification = 'obat_bebas';
    medicineForm.requires_prescription = 0;
    medicineForm.is_active = 1;
    medicineForm.image_path = null;
    showMedicineModal.value = true;
};
const openEditMedicine = (medicine) => {
    medicineMode.value = 'edit';
    selectedMedicine.value = medicine;
    medicineForm.clearErrors();
    Object.keys(medicineForm.data()).forEach((key) => {
        medicineForm[key] = medicine[key] ?? '';
    });
    medicineForm.requires_prescription = medicine.requires_prescription ? 1 : 0;
    medicineForm.is_active = medicine.is_active ? 1 : 0;
    medicineForm.image_path = null;
    showMedicineModal.value = true;
};
const selectMedicineImage = (event) => {
    medicineForm.image_path = event.target.files?.[0] ?? null;
};
const submitMedicine = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showMedicineModal.value = false;
            medicineForm.reset();
        },
    };
    medicineMode.value === 'create'
        ? medicineForm.post(route('medicines.store'), options)
        : medicineForm.patch(route('medicines.update', selectedMedicine.value.id), options);
};

const openCreateBatch = () => {
    batchMode.value = 'create';
    selectedBatch.value = null;
    batchForm.reset();
    batchForm.clearErrors();
    batchForm.status = 'available';
    batchForm.initial_stock = 0;
    showBatchModal.value = true;
};
const openEditBatch = (batch) => {
    batchMode.value = 'edit';
    selectedBatch.value = batch;
    batchForm.clearErrors();
    Object.keys(batchForm.data()).forEach((key) => {
        batchForm[key] = batch[key] ?? '';
    });
    showBatchModal.value = true;
};
const submitBatch = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showBatchModal.value = false;
            batchForm.reset();
        },
    };
    batchMode.value === 'create'
        ? batchForm.post(route('medicine-batches.store'), options)
        : batchForm.patch(route('medicine-batches.update', selectedBatch.value.id), options);
};

useRealtimeFilters(medicineFilters, () => route('medicines.index'), {
    data: () => ({ ...medicineFilters.value, tab: 'medicines' }),
    canVisit: () => activeTab.value === 'medicines',
});
useRealtimeFilters(batchFilters, () => route('medicines.index'), {
    data: () => ({ ...batchFilters.value, tab: 'batches' }),
    canVisit: () => activeTab.value === 'batches',
});
const confirmDelete = (type, row) => {
    deleteType.value = type;
    deleteTarget.value = row;
    showDeleteModal.value = true;
};
const destroy = () => {
    const routeName = deleteType.value === 'medicine' ? 'medicines.destroy' : 'medicine-batches.destroy';
    deleteProcessing.value = true;

    router.delete(route(routeName, deleteTarget.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            showDeleteModal.value = false;
        },
    });
};
</script>

<template>
    <Head title="Obat dan Batch" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div v-if="canManage" class="flex justify-end">
                <div class="flex gap-2">
                    <UiButton v-if="activeTab === 'medicines'" @click="openCreateMedicine">
                        <Plus class="h-4 w-4" />
                        Tambah Obat
                    </UiButton>
                    <UiButton v-else @click="openCreateBatch">
                        <Plus class="h-4 w-4" />
                        Tambah Batch
                    </UiButton>
                </div>
            </div>

            <div class="inline-flex rounded-md border border-slate-200 bg-white p-1 shadow-sm">
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'medicines' ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-700'"
                    @click="activeTab = 'medicines'"
                >
                    Daftar Obat
                </button>
                <button
                    v-if="canManage"
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'batches' ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-700'"
                    @click="activeTab = 'batches'"
                >
                    Daftar Batch
                </button>
            </div>

            <DataTable v-if="activeTab === 'medicines'" :columns="medicineColumns" :rows="medicines.data" empty-title="Belum ada obat">
                <template #filters>
                    <form class="grid gap-3 lg:grid-cols-[1fr_14rem_12rem]" @submit.prevent>
                        <FormInput id="search" v-model="medicineFilters.search" label="Pencarian" placeholder="Kode, barcode, nama" />
                        <SelectInput id="classification" v-model="medicineFilters.classification" label="Klasifikasi" :options="options.classifications" placeholder="Semua klasifikasi" />
                        <SelectInput v-if="canManage" id="status" v-model="medicineFilters.status" label="Status" :options="medicineStatusOptions" placeholder="Semua status" />
                    </form>
                </template>

                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'name'">
                        <div class="font-semibold text-slate-950">{{ row.name }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.code }}<span v-if="row.barcode"> · {{ row.barcode }}</span></div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.category }} · {{ row.unit }} · {{ row.dosage_form ?? '-' }}</div>
                    </template>
                    <template v-else-if="column.key === 'classification'">{{ labelFor(options.classifications, row.classification) }}</template>
                    <template v-else-if="column.key === 'selling_price'">{{ formatCurrency(value) }}</template>
                    <template v-else-if="column.key === 'minimum_stock'">{{ formatQuantity(value) }}</template>
                    <template v-else-if="column.key === 'is_active'">
                        <StatusBadge :status="row.is_active ? 'active' : 'inactive'" :label="row.is_active ? 'Aktif' : 'Nonaktif'" />
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <div v-if="canManage" class="flex justify-end gap-2">
                            <IconButton label="Ubah obat" @click="openEditMedicine(row)">
                                <Pencil class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Hapus obat" variant="danger" :disabled="!row.can_delete" @click="confirmDelete('medicine', row)">
                                <Trash2 class="h-4 w-4" />
                            </IconButton>
                        </div>
                    </template>
                    <template v-else>{{ value }}</template>
                </template>

                <template #pagination>
                    <Pagination :meta="medicines" />
                </template>
            </DataTable>

            <DataTable v-else :columns="batchColumns" :rows="batches?.data ?? []" empty-title="Belum ada batch">
                <template #filters>
                    <form class="grid gap-3 xl:grid-cols-[1fr_14rem_12rem_12rem]" @submit.prevent>
                        <FormInput id="batch_search" v-model="batchFilters.batch_search" label="Pencarian" placeholder="Batch atau obat" />
                        <SelectInput id="medicine_id" v-model="batchFilters.medicine_id" label="Obat" :options="medicineOptions" placeholder="Semua obat" />
                        <SelectInput id="supplier_id" v-model="batchFilters.supplier_id" label="Supplier" :options="supplierOptions" placeholder="Semua supplier" />
                        <SelectInput id="batch_status" v-model="batchFilters.batch_status" label="Status" :options="options.batch_statuses" placeholder="Semua status" />
                    </form>
                </template>

                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'batch_number'">
                        <div class="font-semibold text-slate-950">{{ row.batch_number }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.supplier ?? '-' }}</div>
                    </template>
                    <template v-else-if="column.key === 'medicine'">
                        <div class="font-semibold text-slate-950">{{ row.medicine }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.medicine_code }}</div>
                    </template>
                    <template v-else-if="column.key === 'expiry_date'">{{ formatDate(value) }}</template>
                    <template v-else-if="column.key === 'current_stock'">{{ formatQuantity(value) }}</template>
                    <template v-else-if="column.key === 'status'"><StatusBadge :status="value" /></template>
                    <template v-else-if="column.key === 'actions'">
                        <div class="flex justify-end gap-2">
                            <IconButton label="Ubah batch" @click="openEditBatch(row)">
                                <Pencil class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Hapus batch" variant="danger" @click="confirmDelete('batch', row)">
                                <Trash2 class="h-4 w-4" />
                            </IconButton>
                        </div>
                    </template>
                    <template v-else>{{ value }}</template>
                </template>

                <template #pagination>
                    <Pagination :meta="batches" />
                </template>
            </DataTable>
        </div>

        <FloatingFormModal
            :show="showMedicineModal"
            :title="medicineMode === 'create' ? 'Tambah Obat' : 'Ubah Obat'"
            :processing="medicineForm.processing"
            max-width="2xl"
            @close="showMedicineModal = false"
            @submit="submitMedicine"
        >
            <div class="grid gap-4 md:grid-cols-2">
                <FormInput id="code" v-model="medicineForm.code" label="Kode" help="Kosongkan untuk kode otomatis" :error="medicineForm.errors.code" />
                <BarcodeInput id="barcode" v-model="medicineForm.barcode" label="Barcode" :error="medicineForm.errors.barcode" />
                <FormInput id="name" v-model="medicineForm.name" label="Nama Obat" required :error="medicineForm.errors.name" />
                <FormInput id="generic_name" v-model="medicineForm.generic_name" label="Nama Generik" :error="medicineForm.errors.generic_name" />
                <SelectInput id="medicine_category_id" v-model="medicineForm.medicine_category_id" label="Kategori" :options="categoryOptions" required :error="medicineForm.errors.medicine_category_id" />
                <SelectInput id="unit_id" v-model="medicineForm.unit_id" label="Satuan" :options="unitOptions" required :error="medicineForm.errors.unit_id" />
                <SelectInput id="dosage_form_id" v-model="medicineForm.dosage_form_id" label="Bentuk Sediaan" :options="dosageFormOptions" :error="medicineForm.errors.dosage_form_id" />
                <SelectInput id="classification" v-model="medicineForm.classification" label="Klasifikasi" :options="options.classifications" required :error="medicineForm.errors.classification" />
                <FormInput id="manufacturer" v-model="medicineForm.manufacturer" label="Produsen" :error="medicineForm.errors.manufacturer" />
                <FormInput id="registration_number" v-model="medicineForm.registration_number" label="Nomor Registrasi" :error="medicineForm.errors.registration_number" />
                <FormInput id="active_ingredient" v-model="medicineForm.active_ingredient" label="Kandungan Aktif" :error="medicineForm.errors.active_ingredient" />
                <FormInput id="strength" v-model="medicineForm.strength" label="Kekuatan" :error="medicineForm.errors.strength" />
                <CurrencyInput id="default_purchase_price" v-model="medicineForm.default_purchase_price" label="Harga Beli Bawaan" required :error="medicineForm.errors.default_purchase_price" />
                <CurrencyInput id="selling_price" v-model="medicineForm.selling_price" label="Harga Jual" required :error="medicineForm.errors.selling_price" />
                <NumberInput id="minimum_stock" v-model="medicineForm.minimum_stock" label="Stok Minimum" required step="0.001" :error="medicineForm.errors.minimum_stock" />
                <NumberInput id="reorder_level" v-model="medicineForm.reorder_level" label="Batas Reorder" required step="0.001" :error="medicineForm.errors.reorder_level" />
                <SelectInput id="requires_prescription" v-model="medicineForm.requires_prescription" label="Butuh Resep" :options="booleanOptions" required :error="medicineForm.errors.requires_prescription" />
                <SelectInput id="is_active" v-model="medicineForm.is_active" label="Status" :options="activeOptions" required :error="medicineForm.errors.is_active" />
                <div class="md:col-span-2">
                    <label for="image_path" class="block text-sm font-semibold text-slate-700">Gambar Obat</label>
                    <input
                        id="image_path"
                        type="file"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        class="mt-1 block min-h-10 w-full rounded-md border border-slate-300 text-sm text-slate-900 shadow-sm file:mr-3 file:min-h-10 file:border-0 file:bg-slate-100 file:px-3 file:text-sm file:font-semibold file:text-slate-700 focus:border-emerald-500 focus:ring-emerald-500"
                        :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': medicineForm.errors.image_path }"
                        @change="selectMedicineImage"
                    />
                    <p v-if="selectedMedicine?.image_path && medicineMode === 'edit'" class="mt-1 text-xs text-slate-500">Kosongkan jika tidak ingin mengganti gambar.</p>
                    <p v-if="medicineForm.errors.image_path" class="mt-1 text-xs font-medium text-red-600">{{ medicineForm.errors.image_path }}</p>
                </div>
                <TextareaInput id="storage_instruction" v-model="medicineForm.storage_instruction" class="md:col-span-2" label="Instruksi Penyimpanan" :error="medicineForm.errors.storage_instruction" />
            </div>
        </FloatingFormModal>

        <FloatingFormModal
            :show="showBatchModal"
            :title="batchMode === 'create' ? 'Tambah Batch' : 'Ubah Batch'"
            :processing="batchForm.processing"
            @close="showBatchModal = false"
            @submit="submitBatch"
        >
            <div class="grid gap-4 md:grid-cols-2">
                <SelectInput id="batch_medicine_id" v-model="batchForm.medicine_id" label="Obat" :options="medicineOptions" required :error="batchForm.errors.medicine_id" />
                <SelectInput id="batch_supplier_id" v-model="batchForm.supplier_id" label="Supplier" :options="batchMode === 'create' ? activeSupplierOptions : supplierOptions" :error="batchForm.errors.supplier_id" />
                <FormInput id="batch_number" v-model="batchForm.batch_number" label="Nomor Batch" help="Kosongkan untuk AUTO-YYYYMMDD-####" :error="batchForm.errors.batch_number" />
                <DateInput id="expiry_date" v-model="batchForm.expiry_date" label="Tanggal Kedaluwarsa" :error="batchForm.errors.expiry_date" />
                <CurrencyInput id="purchase_price" v-model="batchForm.purchase_price" label="Harga Beli" required :error="batchForm.errors.purchase_price" />
                <CurrencyInput id="batch_selling_price" v-model="batchForm.selling_price" label="Harga Jual Batch" :error="batchForm.errors.selling_price" />
                <NumberInput v-if="batchMode === 'create'" id="initial_stock" v-model="batchForm.initial_stock" label="Stok Awal" required step="0.001" :error="batchForm.errors.initial_stock" />
                <DateInput id="received_date" v-model="batchForm.received_date" label="Tanggal Terima" :error="batchForm.errors.received_date" />
                <SelectInput id="batch_status" v-model="batchForm.status" label="Status" :options="options.batch_statuses" required :error="batchForm.errors.status" />
                <TextareaInput id="notes" v-model="batchForm.notes" class="md:col-span-2" label="Catatan" :error="batchForm.errors.notes" />
            </div>
        </FloatingFormModal>

        <DeleteConfirmationModal
            :show="showDeleteModal"
            :item-name="deleteType === 'medicine' ? deleteTarget?.name : deleteTarget?.batch_number"
            :processing="deleteProcessing"
            @close="showDeleteModal = false"
            @confirm="destroy"
        />
    </AuthenticatedLayout>
</template>
