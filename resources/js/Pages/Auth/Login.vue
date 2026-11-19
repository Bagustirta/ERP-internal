<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';

defineProps({
    demoUsers: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    email: 'admin@erp.local',
    password: 'password',
    remember: true,
});

const submit = () => {
    form.post('/login');
};

const loginAsRole = (user) => {
    form.email = user.email;
    form.password = 'password';
    form.post('/login');
};
</script>

<template>
    <Head title="Masuk — Nusantara ERP" />

    <div class="min-h-screen flex flex-col justify-center items-center px-4 py-10 bg-[#f5f5f3] dark:bg-[#0c0c0e] text-zinc-900 dark:text-zinc-100">
        <div class="w-full max-w-[380px]">
            <div class="mb-6">
                <div class="inline-flex items-center gap-2 mb-3">
                    <div class="w-6 h-6 rounded-[5px] erp-btn-primary font-mono font-bold text-xs flex items-center justify-center">
                        N
                    </div>
                    <span class="font-semibold text-sm tracking-tight">Nusantara ERP</span>
                </div>
                <h1 class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">Masuk ke Workspace Internal</h1>
                <p class="text-xs text-zinc-500 mt-0.5">PT Nusantara Distribusi Mandiri</p>
            </div>

            <div class="bg-white dark:bg-[#131316] rounded-lg border border-zinc-200/90 dark:border-zinc-800 shadow-[0_1px_3px_rgba(0,0,0,0.03)] p-5">
                <form @submit.prevent="submit" class="space-y-3.5">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Email</label>
                        <input
                            v-model="form.email"
                            type="email"
                            required
                            autofocus
                            class="w-full px-3 py-1.5 text-xs rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-none focus:border-zinc-900 dark:focus:border-zinc-400 transition"
                        />
                        <p v-if="form.errors.email" class="text-[11px] text-rose-600 mt-1">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Kata Sandi</label>
                        <input
                            v-model="form.password"
                            type="password"
                            required
                            class="w-full px-3 py-1.5 text-xs rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-none focus:border-zinc-900 dark:focus:border-zinc-400 transition"
                        />
                    </div>

                    <div class="flex items-center justify-between text-xs pt-0.5">
                        <label class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400 cursor-pointer">
                            <input v-model="form.remember" type="checkbox" class="rounded border-zinc-300 text-zinc-900" />
                            <span>Tetap masuk</span>
                        </label>
                        <span class="text-[11px] text-zinc-400 font-mono">pw: password</span>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full py-2 px-3 rounded-[5px] erp-btn-primary font-medium text-xs transition disabled:opacity-50"
                    >
                        {{ form.processing ? 'Memverifikasi...' : 'Lanjutkan' }}
                    </button>
                </form>
            </div>

            <!-- Subtle Quick Access List -->
            <div class="mt-5">
                <div class="text-[10.5px] font-medium uppercase tracking-wider text-zinc-400 mb-2 px-1">
                    Akses Cepat Akun Departemen
                </div>
                <div class="bg-white dark:bg-[#131316] rounded-lg border border-zinc-200/80 dark:border-zinc-800 divide-y divide-zinc-100 dark:divide-zinc-800/80 overflow-hidden">
                    <button
                        v-for="u in demoUsers"
                        :key="u.id"
                        type="button"
                        @click="loginAsRole(u)"
                        class="w-full px-3 py-2 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/60 flex items-center justify-between transition group"
                    >
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="text-xs font-medium text-zinc-800 dark:text-zinc-200 truncate">{{ u.role_name }}</span>
                            <span class="text-[11px] font-mono text-zinc-400 truncate">{{ u.email }}</span>
                        </div>
                        <ArrowRight class="w-3.5 h-3.5 text-zinc-300 group-hover:text-zinc-700 dark:group-hover:text-zinc-200 transition shrink-0" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
