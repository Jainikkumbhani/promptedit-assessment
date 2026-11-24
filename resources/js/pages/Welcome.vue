<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BadgeCheck, Gauge, ShieldCheck, Sparkles, Wand2 } from 'lucide-vue-next';

const features = [
    { icon: Gauge, title: 'Price before you press', text: 'A live estimate as you type, built from Pixelforge token rates.' },
    { icon: BadgeCheck, title: 'Exact, never twice', text: 'Charged from real token usage to the nanodollar, once per image.' },
    { icon: ShieldCheck, title: 'Failures are free', text: 'If an image fails, the reserved credits come straight back.' },
];
</script>

<template>
    <Head title="Welcome" />

    <div class="relative isolate flex min-h-screen flex-col overflow-hidden bg-background text-foreground">
        <div class="aurora" aria-hidden="true" />
        <div
            class="pointer-events-none absolute inset-0 -z-10 bg-[linear-gradient(to_right,hsl(var(--border)/0.5)_1px,transparent_1px),linear-gradient(to_bottom,hsl(var(--border)/0.5)_1px,transparent_1px)] bg-[size:56px_56px] [mask-image:radial-gradient(ellipse_at_center,black_20%,transparent_70%)]"
        />

        <header class="mx-auto flex w-full max-w-6xl items-center justify-between p-6">
            <div class="flex items-center gap-2 font-semibold">
                <span class="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-violet-500 via-fuchsia-500 to-cyan-400"
                    ><Sparkles class="size-4 text-white"
                /></span>
                PromptEdit Studio
            </div>
            <nav class="flex items-center gap-2 text-sm">
                <Link :href="route('login')" class="rounded-lg px-4 py-2 text-muted-foreground transition hover:text-foreground">Log in</Link>
                <Link :href="route('register')" class="btn-glow rounded-lg px-4 py-2 font-semibold">Get started</Link>
            </nav>
        </header>

        <main class="mx-auto flex w-full max-w-6xl flex-1 flex-col items-center justify-center px-6 pb-20 pt-10 text-center">
            <p
                class="inline-flex animate-fade-up items-center gap-2 rounded-full border border-violet-500/30 bg-violet-500/10 px-3 py-1 text-xs font-medium text-violet-300"
            >
                <Sparkles class="size-3.5" /> $2 of free credits when you sign up
            </p>
            <h1 class="mt-6 max-w-3xl animate-fade-up text-5xl font-semibold tracking-tight [animation-delay:80ms] md:text-7xl">
                Imagine it.<br /><span class="text-gradient">Know what it costs.</span>
            </h1>
            <p class="mt-6 max-w-xl animate-fade-up text-lg text-muted-foreground [animation-delay:160ms]">
                Generate images with Pixelforge and pay the exact cost of each one. No surprises, no double charges, nothing for failures.
            </p>
            <div class="mt-10 flex animate-fade-up flex-wrap justify-center gap-3 [animation-delay:240ms]">
                <Link :href="route('register')" class="btn-glow inline-flex items-center gap-2 rounded-xl px-6 py-3 font-semibold"
                    ><Wand2 class="size-4" /> Start creating</Link
                >
                <Link :href="route('login')" class="glass rounded-xl px-6 py-3 font-medium transition hover:border-violet-400/50"
                    >I have an account</Link
                >
            </div>

            <div class="mt-20 grid w-full gap-4 text-left md:grid-cols-3">
                <div
                    v-for="(feature, i) in features"
                    :key="feature.title"
                    class="glass animate-pop-in p-6 transition duration-300 hover:-translate-y-1"
                    :style="{ animationDelay: `${320 + i * 90}ms` }"
                >
                    <span class="inline-flex rounded-lg bg-violet-500/15 p-2 text-violet-300"><component :is="feature.icon" class="size-5" /></span>
                    <h3 class="mt-4 font-semibold">{{ feature.title }}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">{{ feature.text }}</p>
                </div>
            </div>
        </main>
    </div>
</template>
