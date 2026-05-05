<script module lang="ts">
    export const layout = {
        title: 'ArchiBot setup',
        description:
            'Connect ArchiBot to Paperless-NGX with a Paperless admin account',
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import PasswordInput from '@/components/PasswordInput.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';

    let { requiresResetToken = false }: { requiresResetToken: boolean } =
        $props();
</script>

<AppHead title="ArchiBot setup" />

<div
    class="mx-auto flex min-h-svh w-full max-w-xl flex-col justify-center gap-6 px-6 py-12"
>
    <div class="space-y-2 text-center">
        <h1 class="text-3xl font-semibold tracking-tight">
            Connect Paperless-NGX
        </h1>
        <p class="text-sm text-muted-foreground">
            Setup must be completed with a Paperless superuser/admin. ArchiBot
            stores the Paperless URL globally and stores the admin user's
            Paperless API token encrypted.
        </p>
    </div>

    <Form
        action="/setup"
        method="post"
        resetOnSuccess={['password', 'setup_token']}
        class="grid gap-5 rounded-xl border bg-card p-6 shadow-sm"
    >
        {#snippet children({ errors, processing })}
            {#if requiresResetToken}
                <div class="grid gap-2">
                    <Label for="setup_token">Temporary setup token</Label>
                    <PasswordInput
                        id="setup_token"
                        name="setup_token"
                        required
                        autocomplete="one-time-code"
                    />
                    <p class="text-xs text-muted-foreground">
                        Generate this with <code
                            >php artisan archibot:setup-reset</code
                        >. It expires after 10 minutes.
                    </p>
                    <InputError message={errors.setup_token} />
                </div>
            {/if}

            <div class="grid gap-2">
                <Label for="paperless_url">Paperless-NGX URL</Label>
                <Input
                    id="paperless_url"
                    name="paperless_url"
                    type="url"
                    required
                    placeholder="https://paperless.example.test"
                />
                <InputError message={errors.paperless_url} />
            </div>

            <div class="grid gap-2">
                <Label for="username">Paperless username</Label>
                <Input
                    id="username"
                    name="username"
                    required
                    autocomplete="username"
                />
                <InputError message={errors.username} />
            </div>

            <div class="grid gap-2">
                <Label for="password">Paperless password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                />
                <InputError message={errors.password} />
            </div>

            <Button type="submit" disabled={processing} class="w-full">
                {#if processing}<Spinner />{/if}
                Complete setup
            </Button>
        {/snippet}
    </Form>
</div>
