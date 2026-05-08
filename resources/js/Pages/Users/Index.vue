<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DeleteConfirmationModal from '@/Components/UI/DeleteConfirmationModal.vue';
import FloatingFormModal from '@/Components/UI/FloatingFormModal.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import UiButton from '@/Components/UI/UiButton.vue';
import { useRealtimeFilters } from '@/Composables/useRealtimeFilters';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, required: true },
    stats: { type: Object, required: true },
});

const showFormModal = ref(false);
const showDeleteModal = ref(false);
const deleteProcessing = ref(false);
const selectedUser = ref(null);
const mode = ref('create');
const filterForm = ref({
    search: props.filters.search ?? '',
    role: props.filters.role ?? '',
    status: props.filters.status ?? '',
});

const form = useForm({
    name: '',
    email: '',
    phone: '',
    role: 'employee',
    is_active: 1,
    password: '',
    password_confirmation: '',
});

const columns = [
    { key: 'name', label: 'Pengguna', sortable: true },
    { key: 'role', label: 'Role' },
    { key: 'is_active', label: 'Status' },
    { key: 'created_at', label: 'Dibuat' },
    { key: 'actions', label: '', align: 'right' },
];

const roleOptions = [
    { value: 'super_admin', label: 'Super Admin' },
    { value: 'admin', label: 'Admin' },
    { value: 'employee', label: 'Karyawan' },
];

const statusOptions = [
    { value: 'active', label: 'Aktif' },
    { value: 'inactive', label: 'Nonaktif' },
];

const activeOptions = [
    { value: 1, label: 'Aktif' },
    { value: 0, label: 'Nonaktif' },
];

const modalTitle = computed(() => mode.value === 'create' ? 'Tambah Pengguna' : 'Ubah Pengguna');
const submitLabel = computed(() => mode.value === 'create' ? 'Buat Pengguna' : 'Simpan Perubahan');

const roleLabel = (role) => roleOptions.find((option) => option.value === role)?.label ?? role;
const formatDate = (value) => value
    ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value))
    : '-';

const openCreate = () => {
    mode.value = 'create';
    selectedUser.value = null;
    form.reset();
    form.clearErrors();
    form.role = 'employee';
    form.is_active = 1;
    showFormModal.value = true;
};

const openEdit = (user) => {
    mode.value = 'edit';
    selectedUser.value = user;
    form.clearErrors();
    form.name = user.name;
    form.email = user.email;
    form.phone = user.phone ?? '';
    form.role = user.role;
    form.is_active = user.is_active ? 1 : 0;
    form.password = '';
    form.password_confirmation = '';
    showFormModal.value = true;
};

const closeForm = () => {
    if (!form.processing) {
        showFormModal.value = false;
    }
};

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showFormModal.value = false;
            form.reset();
        },
    };

    if (mode.value === 'create') {
        form.post(route('users.store'), options);
    } else {
        form.patch(route('users.update', selectedUser.value.id), options);
    }
};

const openDelete = (user) => {
    selectedUser.value = user;
    showDeleteModal.value = true;
};

const destroy = () => {
    deleteProcessing.value = true;

    router.delete(route('users.destroy', selectedUser.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            showDeleteModal.value = false;
        },
    });
};

useRealtimeFilters(filterForm, () => route('users.index'));
</script>

<template>
    <Head title="Pengguna" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div class="flex justify-end">
                <UiButton @click="openCreate">
                    <Plus class="h-4 w-4" />
                    Tambah Pengguna
                </UiButton>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-sm font-medium text-slate-500">Total</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-950">{{ stats.total }}</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-sm font-medium text-slate-500">Aktif</div>
                    <div class="mt-2 text-2xl font-semibold text-emerald-700">{{ stats.active }}</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-sm font-medium text-slate-500">Nonaktif</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-700">{{ stats.inactive }}</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-sm font-medium text-slate-500">Super Admin</div>
                    <div class="mt-2 text-2xl font-semibold text-emerald-700">{{ stats.super_admin }}</div>
                </div>
            </div>

            <DataTable
                :columns="columns"
                :rows="users.data"
                empty-title="Belum ada pengguna"
                empty-description="Pengguna yang dibuat Super Admin akan tampil di sini."
            >
                <template #filters>
                    <form class="grid gap-3 lg:grid-cols-[1fr_12rem_12rem]" @submit.prevent>
                        <FormInput id="search" v-model="filterForm.search" label="Pencarian" placeholder="Nama, email, atau telepon" />
                        <SelectInput id="role" v-model="filterForm.role" label="Peran" :options="roleOptions" placeholder="Semua peran" />
                        <SelectInput id="status" v-model="filterForm.status" label="Status" :options="statusOptions" placeholder="Semua status" />
                    </form>
                </template>

                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'name'">
                        <div class="font-semibold text-slate-950">{{ row.name }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.email }}<span v-if="row.phone"> · {{ row.phone }}</span></div>
                    </template>
                    <template v-else-if="column.key === 'role'">
                        <StatusBadge status="active" :label="roleLabel(row.role)" />
                    </template>
                    <template v-else-if="column.key === 'is_active'">
                        <StatusBadge :status="row.is_active ? 'active' : 'inactive'" :label="row.is_active ? 'Aktif' : 'Nonaktif'" />
                    </template>
                    <template v-else-if="column.key === 'created_at'">
                        {{ formatDate(value) }}
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <div class="flex justify-end gap-2">
                            <IconButton label="Ubah pengguna" @click="openEdit(row)">
                                <Pencil class="h-4 w-4" />
                            </IconButton>
                            <IconButton
                                label="Hapus pengguna"
                                variant="danger"
                                :disabled="!row.can_delete"
                                @click="openDelete(row)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </IconButton>
                        </div>
                    </template>
                    <template v-else>{{ value }}</template>
                </template>

                <template #pagination>
                    <Pagination :meta="users" />
                </template>
            </DataTable>
        </div>

        <FloatingFormModal
            :show="showFormModal"
            :title="modalTitle"
            :processing="form.processing"
            :submit-label="submitLabel"
            @close="closeForm"
            @submit="submit"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <FormInput id="name" v-model="form.name" label="Nama" required :error="form.errors.name" />
                <FormInput id="email" v-model="form.email" type="email" label="Email" required :error="form.errors.email" />
                <FormInput id="phone" v-model="form.phone" label="Telepon" :error="form.errors.phone" />
                <SelectInput id="role" v-model="form.role" label="Peran" :options="roleOptions" required :error="form.errors.role" />
                <SelectInput id="is_active" v-model="form.is_active" label="Status" :options="activeOptions" required :error="form.errors.is_active" />
                <div class="hidden sm:block" />
                <FormInput id="password" v-model="form.password" type="password" label="Kata Sandi" :required="mode === 'create'" :error="form.errors.password" />
                <FormInput id="password_confirmation" v-model="form.password_confirmation" type="password" label="Konfirmasi Kata Sandi" :required="mode === 'create'" :error="form.errors.password_confirmation" />
            </div>
        </FloatingFormModal>

        <DeleteConfirmationModal
            :show="showDeleteModal"
            :item-name="selectedUser?.email"
            :processing="deleteProcessing"
            @close="showDeleteModal = false"
            @confirm="destroy"
        />
    </AuthenticatedLayout>
</template>
