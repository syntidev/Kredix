<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import BackButton from '../../Components/BackButton.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineOptions({ layout: AppLayout });

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
    <Head title="Sistema" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <BackButton href="/home" label="Inicio" />
        <h1 class="text-xl font-semibold text-kredix-negro">Sistema</h1>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6">
            <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" />
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6">
            <UpdatePasswordForm />
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6">
            <DeleteUserForm />
        </div>
    </div>
</template>
