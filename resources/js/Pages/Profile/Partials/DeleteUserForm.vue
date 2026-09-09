<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => passwordInput.value.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section class="flex flex-col gap-4">
        <header>
            <h2 class="text-sm font-semibold text-kredix-negro">
                Eliminar cuenta
            </h2>

            <p class="mt-1 text-sm text-kredix-gris">
                Una vez eliminada la cuenta, todos sus datos se borran de forma
                permanente. Descarga lo que necesites conservar antes de continuar.
            </p>
        </header>

        <DangerButton @click="confirmUserDeletion">Eliminar cuenta</DangerButton>

        <Modal :show="confirmingUserDeletion" @close="closeModal">
            <div class="p-6">
                <h2 class="text-sm font-semibold text-kredix-negro">
                    ¿Eliminar tu cuenta?
                </h2>

                <p class="mt-1 text-sm text-kredix-gris">
                    Esta accion no se puede deshacer. Ingresa tu contrasena para
                    confirmar la eliminacion permanente de la cuenta.
                </p>

                <div class="mt-6 flex flex-col gap-1">
                    <InputLabel
                        for="password"
                        value="Contrasena"
                        class="sr-only"
                    />

                    <TextInput
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="w-3/4"
                        placeholder="Contrasena"
                        @keyup.enter="deleteUser"
                    />

                    <InputError :message="form.errors.password" />
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="closeModal">
                        Cancelar
                    </SecondaryButton>

                    <DangerButton
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                        @click="deleteUser"
                    >
                        Eliminar cuenta
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>
