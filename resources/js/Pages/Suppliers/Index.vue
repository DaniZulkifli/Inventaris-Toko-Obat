<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DeleteConfirmationModal from '@/Components/UI/DeleteConfirmationModal.vue';
import FloatingFormModal from '@/Components/UI/FloatingFormModal.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import TextareaInput from '@/Components/UI/TextareaInput.vue';
import UiButton from '@/Components/UI/UiButton.vue';
import { useRealtimeFilters } from '@/Composables/useRealtimeFilters';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    suppliers: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const showFormModal = ref(false);
const showDeleteModal = ref(false);
const deleteProcessing = ref(false);
const selected = ref(null);
const mode = ref('create');
const filterForm = ref({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
});

const form = useForm({
    name: '',
    phone: '',
    email: '',
    address: '',
    contact_person: '',
    notes: '',
    is_active: 1,
});

const columns = [
    { key: 'name', label: 'Supplier', sortable: true },
    { key: 'contact_person', label: 'Kontak' },
    { key: 'is_active', label: 'Status' },
    { key: 'batches_count', label: 'Batch' },
    { key: 'actions', label: '', align: 'right' },
];
const statusOptions = [
    { value: 'active', label: 'Aktif' },
    { value: 'inactive', label: 'Nonaktif' },
];
const activeOptions = [
    { value: 1, label: 'Aktif' },
    { value: 0, label: 'Nonaktif' },
];

const openCreate = () => {
    mode.value = 'create';
    selected.value = null;
    form.reset();
    form.clearErrors();
    form.is_active = 1;
    showFormModal.value = true;
};

const openEdit = (supplier) => {
    mode.value = 'edit';
    selected.value = supplier;
    form.clearErrors();
    Object.assign(form, {
        name: supplier.name,
        phone: supplier.phone ?? '',
        email: supplier.email ?? '',
        address: supplier.address ?? '',
        contact_person: supplier.contact_person ?? '',
        notes: supplier.notes ?? '',
        is_active: supplier.is_active ? 1 : 0,
    });
    showFormModal.value = true;
};

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showFormModal.value = false;
            form.reset();
        },
    };
    mode.value === 'create'
        ? form.post(route('suppliers.store'), options)
        : form.patch(route('suppliers.update', selected.value.id), options);
};

useRealtimeFilters(filterForm, () => route('suppliers.index'));

const confirmDelete = (supplier) => {
    selected.value = supplier;
    showDeleteModal.value = true;
};

const destroy = () => {
    deleteProcessing.value = true;

    router.delete(route('suppliers.destroy', selected.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            showDeleteModal.value = false;
        },
    });
};
</script>

<template>
    <Head title="Supplier" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div class="flex justify-end">
                <UiButton @click="openCreate">
                    <Plus class="h-4 w-4" />
                    Tambah Supplier
                </UiButton>
            </div>

            <DataTable :columns="columns" :rows="suppliers.data" empty-title="Belum ada supplier">
                <template #filters>
                    <form class="grid gap-3 lg:grid-cols-[1fr_12rem]" @submit.prevent>
                        <FormInput id="search" v-model="filterForm.search" label="Pencarian" placeholder="Nama, email, kontak" />
                        <SelectInput id="status" v-model="filterForm.status" label="Status" :options="statusOptions" placeholder="Semua status" />
                    </form>
                </template>

                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'name'">
                        <div class="font-semibold text-slate-950">{{ row.name }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.email ?? '-' }} · {{ row.phone ?? '-' }}</div>
                    </template>
                    <template v-else-if="column.key === 'contact_person'">
                        {{ row.contact_person ?? '-' }}
                    </template>
                    <template v-else-if="column.key === 'is_active'">
                        <StatusBadge :status="row.is_active ? 'active' : 'inactive'" :label="row.is_active ? 'Aktif' : 'Nonaktif'" />
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <div class="flex justify-end gap-2">
                            <IconButton label="Ubah supplier" @click="openEdit(row)">
                                <Pencil class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Hapus supplier" variant="danger" :disabled="!row.can_delete" @click="confirmDelete(row)">
                                <Trash2 class="h-4 w-4" />
                            </IconButton>
                        </div>
                    </template>
                    <template v-else>{{ value }}</template>
                </template>

                <template #pagination>
                    <Pagination :meta="suppliers" />
                </template>
            </DataTable>
        </div>

        <FloatingFormModal
            :show="showFormModal"
            :title="mode === 'create' ? 'Tambah Supplier' : 'Ubah Supplier'"
            :processing="form.processing"
            @close="showFormModal = false"
            @submit="submit"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <FormInput id="name" v-model="form.name" label="Nama" required :error="form.errors.name" />
                <SelectInput id="is_active" v-model="form.is_active" label="Status" :options="activeOptions" required :error="form.errors.is_active" />
                <FormInput id="phone" v-model="form.phone" label="Telepon" :error="form.errors.phone" />
                <FormInput id="email" v-model="form.email" type="email" label="Email" :error="form.errors.email" />
                <FormInput id="contact_person" v-model="form.contact_person" label="Contact Person" :error="form.errors.contact_person" />
                <div class="hidden sm:block" />
                <TextareaInput id="address" v-model="form.address" label="Alamat" :error="form.errors.address" />
                <TextareaInput id="notes" v-model="form.notes" label="Catatan" :error="form.errors.notes" />
            </div>
        </FloatingFormModal>

        <DeleteConfirmationModal
            :show="showDeleteModal"
            :item-name="selected?.name"
            :processing="deleteProcessing"
            @close="showDeleteModal = false"
            @confirm="destroy"
        />
    </AuthenticatedLayout>
</template>
