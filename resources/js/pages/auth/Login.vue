<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import FlowBrand from '@/components/flowtrack/FlowBrand.vue';

const props = defineProps<{ status?: string; canResetPassword: boolean }>();
const form = useForm({ email: 'manager@flowtrack.cn', password: 'demo123', remember: true });

function submit() {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />
    <main class="ft-login">
        <section class="ft-login-visual">
            <div>
                <FlowBrand />
                <h1>One Job. Every phase. Clear ownership.</h1>
                <p>A practical workspace for promotional-product sales, artwork, samples, manufacturing, shipping, invoicing and collection.</p>
                <div class="ft-flow-preview">
                    <div>01 · Request & quotation</div>
                    <div>02 · Artwork & sample</div>
                    <div>03 · Production & quality</div>
                    <div>04 · Shipment & payment</div>
                </div>
            </div>
            <div class="ft-small" style="color: #a9bdd2">Dynamic Laravel + Vue application</div>
        </section>

        <section class="ft-login-form-wrap">
            <form class="ft-login-form" @submit.prevent="submit">
                <div style="margin-bottom: 30px"><FlowBrand /></div>
                <h2 style="font-size: 30px; margin: 0 0 8px; font-weight: 760">Welcome back</h2>
                <p class="ft-muted" style="line-height: 1.6; margin: 0 0 28px">Sign in to manage jobs, assignments and client delivery.</p>
                <div v-if="props.status" class="ft-source-note" style="margin-bottom: 16px">{{ props.status }}</div>
                <div class="ft-field">
                    <label for="email">Email</label>
                    <input id="email" v-model="form.email" type="email" autocomplete="email" autofocus required />
                    <span v-if="form.errors.email" class="ft-error">{{ form.errors.email }}</span>
                </div>
                <div class="ft-field">
                    <div style="display: flex; justify-content: space-between; gap: 12px">
                        <label for="password">Password</label>
                        <Link v-if="canResetPassword" href="/forgot-password" style="font-size: 12px; color: var(--ft-blue)">Forgot password?</Link>
                    </div>
                    <input id="password" v-model="form.password" type="password" autocomplete="current-password" required />
                    <span v-if="form.errors.password" class="ft-error">{{ form.errors.password }}</span>
                </div>
                <label class="ft-check-row" style="border: 0; padding: 0 0 16px">
                    <input v-model="form.remember" type="checkbox" />
                    <span class="ft-small">Remember me</span>
                </label>
                <button class="ft-primary" style="width: 100%; padding: 13px" :disabled="form.processing">{{ form.processing ? 'Signing in…' : 'Sign in' }}</button>
                <div style="margin-top: 16px; padding: 13px; border-radius: 10px; background: #f7f9fc; color: var(--ft-muted); font-size: 12px; line-height: 1.6">
                    <b>Demo access:</b> manager@flowtrack.cn / demo123
                </div>
            </form>
        </section>
    </main>
</template>
