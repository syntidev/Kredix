<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { Share, X } from '@lucide/vue';

const IOS_BANNER_SEEN_KEY = 'kredix_ios_install_banner_seen';

const showIosBanner = ref(false);
const showAndroidButton = ref(false);
let deferredPrompt = null;

function isIos() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
}

function dismissIosBanner() {
    showIosBanner.value = false;
}

function onBeforeInstallPrompt(event) {
    event.preventDefault();
    deferredPrompt = event;
    showAndroidButton.value = true;
}

async function installAndroid() {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    await deferredPrompt.userChoice;
    deferredPrompt = null;
    showAndroidButton.value = false;
}

onMounted(() => {
    if (isIos() && navigator.standalone === false && !localStorage.getItem(IOS_BANNER_SEEN_KEY)) {
        showIosBanner.value = true;
        localStorage.setItem(IOS_BANNER_SEEN_KEY, '1');
    }
    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt);
});

onUnmounted(() => {
    window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt);
});
</script>

<template>
    <div v-if="showIosBanner" class="flex items-center justify-between gap-3 bg-kredix-negro px-4 py-2 text-sm text-white">
        <p class="flex items-center gap-1.5">
            Instala Kredix: toca
            <Share :size="16" class="shrink-0" />
            Compartir y luego "Agregar a inicio"
        </p>
        <button type="button" aria-label="Cerrar aviso de instalacion" class="shrink-0" @click="dismissIosBanner">
            <X :size="18" />
        </button>
    </div>

    <button
        v-if="showAndroidButton"
        type="button"
        class="fixed bottom-20 right-4 z-20 rounded-full bg-kredix-negro px-4 py-2 text-sm font-medium text-white shadow-lg md:bottom-4"
        @click="installAndroid"
    >
        Instalar app
    </button>
</template>
