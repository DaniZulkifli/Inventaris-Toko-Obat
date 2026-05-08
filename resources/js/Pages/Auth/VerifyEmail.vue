<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Spinner from '@/Components/UI/Spinner.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});
const logoutForm = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const logout = () => {
    logoutForm.post(route('logout'));
};

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <GuestLayout>
        <Head title="Verifikasi Email" />

        <div class="mb-6">
            <h1 class="text-2xl font-semibold text-slate-950">Verifikasi Email</h1>
            <p class="mt-1 text-sm text-slate-500">Cek email dan klik tautan verifikasi yang dikirimkan.</p>
        </div>

        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700" v-if="verificationLinkSent">
            Link verifikasi baru berhasil dikirim.
        </div>

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton :loading="form.processing">
                    Kirim Ulang Email
                </PrimaryButton>

                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md text-sm font-medium text-slate-600 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:opacity-50"
                    :disabled="logoutForm.processing"
                    @click="logout"
                >
                    <Spinner v-if="logoutForm.processing" size="sm" />
                    Logout
                </button>
            </div>
        </form>
    </GuestLayout>
</template>
