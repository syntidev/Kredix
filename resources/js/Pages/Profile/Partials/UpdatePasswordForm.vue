<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value.focus();
            }
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-sm font-semibold text-kredix-negro">
                Cambiar contrasena
            </h2>

            <p class="mt-1 text-sm text-kredix-gris">
                Usa una contrasena larga y unica para mantener la cuenta segura.
            </p>
        </header>

        <form @submit.prevent="updatePassword" class="mt-4 flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <InputLabel for="current_password" value="Contrasena actual" />

                <TextInput
                    id="current_password"
                    ref="currentPasswordInput"
                    v-model="form.current_password"
                    type="password"
                    class="w-full"
                    autocomplete="current-password"
                />

                <InputError :message="form.errors.current_password" />
            </div>

            <div class="flex flex-col gap-1">
                <InputLabel for="password" value="Nueva contrasena" />

                <TextInput
                    id="password"
                    ref="passwordInput"
                    v-model="form.password"
                    type="password"
                    class="w-full"
                    autocomplete="new-password"
                />

                <InputError :message="form.errors.password" />
            </div>

            <div class="flex flex-col gap-1">
                <InputLabel
                    for="password_confirmation"
                    value="Confirmar contrasena"
                />

                <TextInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="w-full"
                    autocomplete="new-password"
                />

                <InputError :message="form.errors.password_confirmation" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-kredix-gris"
                    >
                        Guardado.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
