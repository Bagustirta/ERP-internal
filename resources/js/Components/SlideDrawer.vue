<script setup>
import { X } from 'lucide-vue-next';

defineProps({
    show: Boolean,
    title: String,
    subtitle: String,
    width: {
        type: String,
        default: 'max-w-2xl',
    },
});

const emit = defineEmits(['close']);
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 overflow-hidden">
            <div
                class="fixed inset-0 bg-zinc-950/35 backdrop-blur-[1px] transition-opacity"
                @click="emit('close')"
            ></div>
            <div class="fixed inset-y-0 right-0 flex max-w-full pl-10">
                <div class="w-screen" :class="width">
                    <div class="flex h-full flex-col bg-white dark:bg-[#121215] shadow-2xl border-l border-zinc-200 dark:border-zinc-800">
                        <div class="flex items-center justify-between px-5 py-3.5 border-b border-zinc-200/80 dark:border-zinc-800">
                            <div>
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 tracking-tight">{{ title }}</h3>
                                <p v-if="subtitle" class="text-[11px] text-zinc-500 dark:text-zinc-400 mt-0.5">{{ subtitle }}</p>
                            </div>
                            <button
                                type="button"
                                @click="emit('close')"
                                class="rounded-[5px] p-1 text-zinc-400 hover:text-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 transition"
                            >
                                <span class="sr-only">Tutup</span>
                                <X class="w-4 h-4" />
                            </button>
                        </div>
                        <div class="relative flex-1 overflow-y-auto p-5">
                            <slot />
                        </div>
                        <div v-if="$slots.footer" class="border-t border-zinc-200/80 dark:border-zinc-800 px-5 py-3 bg-zinc-50/70 dark:bg-zinc-900/70 flex justify-end gap-2">
                            <slot name="footer" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
