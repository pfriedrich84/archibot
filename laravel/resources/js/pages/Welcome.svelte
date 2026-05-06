<script module lang="ts">
    export const layout = null;
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import AppLogo from '@/components/AppLogo.svelte';
    import { dashboard, login } from '@/routes';
</script>

<AppHead title="Welcome" />

<main
    class="flex min-h-svh items-center justify-center bg-background px-6 py-12"
>
    <section
        class="grid w-full max-w-xl gap-8 rounded-2xl border bg-card p-8 shadow-sm"
    >
        <div class="flex items-center gap-3">
            <AppLogo />
        </div>

        <div class="space-y-3">
            <h1 class="text-3xl font-semibold tracking-tight">
                Welcome to ArchiBot
            </h1>
            <p class="text-muted-foreground">
                ArchiBot connects Paperless-NGX with local Ollama models to
                classify inbox documents, propose metadata, and keep every
                change reviewable before it is written back to Paperless.
            </p>
        </div>

        <div class="flex flex-wrap gap-3">
            <Link
                href={login().url}
                class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
            >
                Log in with Paperless
            </Link>
            <Link
                href={dashboard().url}
                class="rounded-md border px-4 py-2 text-sm font-medium hover:bg-muted"
            >
                Open dashboard
            </Link>
        </div>
    </section>
</main>
