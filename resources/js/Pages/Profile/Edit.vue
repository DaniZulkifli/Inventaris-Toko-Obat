<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Profil" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    class="max-w-xl"
                />
            </section>

            <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                <UpdatePasswordForm class="max-w-xl" />
            </section>

            <section class="rounded-md border border-red-100 bg-white p-5 shadow-sm">
                <DeleteUserForm class="max-w-xl" />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
