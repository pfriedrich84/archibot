<script module lang="ts">
    export const layout = {
        title: 'Log in with Paperless-NGX',
        description:
            'Enter your Paperless username and password below to log in',
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import PasswordInput from '@/components/PasswordInput.svelte';
    import { Button } from '@/components/ui/button';
    import { Checkbox } from '@/components/ui/checkbox';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { store } from '@/routes/login';

    let {
        status = '',
        canResetPassword,
        canRegister,
        paperlessUrl = '',
    }: {
        status?: string;
        canResetPassword: boolean;
        canRegister: boolean;
        paperlessUrl?: string;
    } = $props();
</script>

<AppHead title="Log in with Paperless-NGX" />

{#if status}
    <div class="mb-4 text-center text-sm font-medium text-green-600">
        {status}
    </div>
{/if}

<Form
    {...store.form()}
    resetOnSuccess={['password']}
    class="flex flex-col gap-6"
>
    {#snippet children({ errors, processing })}
        <div class="grid gap-6">
            {#if paperlessUrl}
                <div
                    class="rounded-lg border bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
                >
                    Paperless-NGX server:
                    <span class="font-medium text-foreground">{paperlessUrl}</span>
                </div>
            {/if}

            <div class="grid gap-2">
                <Label for="username">Paperless username</Label>
                <Input
                    id="username"
                    name="username"
                    required
                    autocomplete="username"
                    placeholder="paperless-user"
                />
                <InputError message={errors.username} />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Paperless password</Label>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError message={errors.password} />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                disabled={processing}
                data-test="login-button"
            >
                {#if processing}<Spinner />{/if}
                Log in with Paperless
            </Button>
        </div>

        {#if canRegister || canResetPassword}
            <div class="text-center text-sm text-muted-foreground">
                User management is handled in Paperless-NGX.
            </div>
        {/if}
    {/snippet}
</Form>
