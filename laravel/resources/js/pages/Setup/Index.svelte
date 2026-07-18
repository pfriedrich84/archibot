<script module lang="ts">
    export const layout = {
        title: 'ArchiBot setup',
        description:
            'Verify the deployment-pinned Paperless-NGX instance and choose tags',
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AlertError from '@/components/AlertError.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import PasswordInput from '@/components/PasswordInput.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';

    type TagOption = { id: number; name: string };
    type SetupDefaults = {
        inboxTagId?: string;
        processedTagId?: string;
        ocrRequestedTagId?: string;
    };

    let {
        requiresResetToken = false,
        paperlessUrl,
        deploymentWebhookSecretConfigured = false,
        defaults = {},
        actions,
    }: {
        requiresResetToken: boolean;
        paperlessUrl: string;
        deploymentWebhookSecretConfigured?: boolean;
        defaults?: SetupDefaults;
        actions: { store: string; paperlessTags: string };
    } = $props();

    let username = $state('');
    let password = $state('');
    let webhook_secret = $state('');
    let tags = $state<TagOption[]>([]);
    let tagError = $state('');
    let loadingTags = $state(false);
    let inboxTagId = $state((() => defaults.inboxTagId ?? '')());
    let processedTagId = $state((() => defaults.processedTagId ?? '')());
    let ocrRequestedTagId = $state((() => defaults.ocrRequestedTagId ?? '')());

    type SetupStep = 'reset-token' | 'paperless' | 'tags';
    const setupSteps = $derived([
        ...(requiresResetToken
            ? [{ id: 'reset-token' as const, label: 'Reset token' }]
            : []),
        { id: 'paperless' as const, label: 'Paperless' },
        { id: 'tags' as const, label: 'Tags' },
    ]);
    const initialActiveStep = (): SetupStep =>
        requiresResetToken ? 'reset-token' : 'paperless';
    let activeStep = $state<SetupStep>(initialActiveStep());

    function nextStep() {
        const currentIndex = setupSteps.findIndex(
            (step) => step.id === activeStep,
        );
        activeStep = setupSteps[
            Math.min(currentIndex + 1, setupSteps.length - 1)
        ]?.id as SetupStep;
    }

    function previousStep() {
        const currentIndex = setupSteps.findIndex(
            (step) => step.id === activeStep,
        );
        activeStep = setupSteps[Math.max(currentIndex - 1, 0)]?.id as SetupStep;
    }

    const csrfToken = () =>
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

    async function loadPaperlessTags() {
        tagError = '';
        loadingTags = true;

        try {
            const response = await fetch(actions.paperlessTags, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    paperless_url: paperlessUrl,
                    username,
                    password,
                }),
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                const firstError = Object.values(data?.errors ?? {}).flat()[0];

                throw new Error(
                    typeof firstError === 'string'
                        ? firstError
                        : (data?.message ?? 'Connection failed.'),
                );
            }

            tags = data.items;
            inboxTagId ||= String(tags[0]?.id ?? '');
        } catch (error) {
            tagError = error instanceof Error ? error.message : String(error);
        } finally {
            loadingTags = false;
        }
    }
</script>

<AppHead title="ArchiBot setup" />

<div
    class="mx-auto flex min-h-svh w-full max-w-3xl flex-col justify-center gap-6 px-6 py-12"
>
    <div class="space-y-2 text-center">
        <h1 class="text-3xl font-semibold tracking-tight">ArchiBot setup</h1>
        <p class="text-sm text-muted-foreground">
            Verify a live Paperless superuser at the deployment-pinned
            destination, then choose the Paperless tags ArchiBot should use. AI
            provider endpoints become editable only after the administrator
            session is created.
        </p>
    </div>

    <Form
        action={actions.store}
        method="post"
        resetOnSuccess={['password', 'webhook_secret', 'setup_token']}
        novalidate
        class="grid gap-6 rounded-xl border bg-card p-6 shadow-sm"
    >
        {#snippet children({ errors, processing })}
            <nav class="grid gap-2 sm:grid-cols-3" aria-label="Setup steps">
                {#each setupSteps as step, index (step.id)}
                    <Button
                        type="button"
                        variant={activeStep === step.id ? 'default' : 'outline'}
                        class="justify-start"
                        onclick={() => (activeStep = step.id)}
                    >
                        <span
                            class="flex size-6 items-center justify-center rounded-full bg-background/20 text-xs"
                        >
                            {index + 1}
                        </span>
                        {step.label}
                    </Button>
                {/each}
                <Button
                    type="button"
                    variant="outline"
                    class="justify-start"
                    disabled
                    aria-label="AI providers, available after setup completion"
                >
                    <span
                        class="flex size-6 items-center justify-center rounded-full bg-background/20 text-xs"
                    >
                        {setupSteps.length + 1}
                    </span>
                    AI providers
                </Button>
            </nav>

            {#if Object.keys(errors).length > 0}
                <AlertError
                    title="Setup could not be completed."
                    errors={Object.values(errors)}
                />
            {/if}

            {#if requiresResetToken}
                <section
                    class:hidden={activeStep !== 'reset-token'}
                    class="grid gap-2"
                >
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
                    <div class="flex justify-end">
                        <Button type="button" onclick={nextStep}>Next</Button>
                    </div>
                </section>
            {/if}

            <section
                class:hidden={activeStep !== 'paperless'}
                class="grid gap-4"
            >
                <div>
                    <h2 class="text-lg font-semibold">
                        Paperless-NGX trust anchor
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Use a live Paperless superuser account. The destination
                        is owned by <code>PAPERLESS_URL</code> and cannot be changed
                        in setup or admin settings.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="paperless_url_display">
                        Pinned Paperless-NGX origin
                    </Label>
                    <Input
                        id="paperless_url_display"
                        type="url"
                        value={paperlessUrl}
                        readonly
                        aria-readonly="true"
                    />
                    <input
                        type="hidden"
                        name="paperless_url"
                        value={paperlessUrl}
                    />
                    <InputError message={errors.paperless_url} />
                </div>

                <div class="grid gap-2 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="username">Paperless superuser name</Label>
                        <Input
                            id="username"
                            name="username"
                            required
                            autocomplete="username"
                            value={username}
                            oninput={(event) =>
                                (username = event.currentTarget.value)}
                        />
                        <InputError message={errors.username} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="password">Paperless password</Label>
                        <Input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            value={password}
                            oninput={(event) =>
                                (password = event.currentTarget.value)}
                        />
                        <InputError message={errors.password} />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="webhook_secret">Webhook shared secret</Label>
                    <PasswordInput
                        id="webhook_secret"
                        name="webhook_secret"
                        required={!deploymentWebhookSecretConfigured}
                        minlength="32"
                        value={webhook_secret}
                        oninput={(event) =>
                            (webhook_secret = event.currentTarget.value)}
                        autocomplete="new-password"
                    />
                    <p class="text-xs text-muted-foreground">
                        {#if deploymentWebhookSecretConfigured}
                            A deployment secret is configured. Leave this blank
                            to use it.
                        {:else}
                            Required. Generate at least 32 random bytes, save it
                            here, and use the same value in Paperless's <code
                                >X-Webhook-Secret</code
                            > header.
                        {/if}
                    </p>
                    <InputError message={errors.webhook_secret} />
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        onclick={loadPaperlessTags}
                        disabled={loadingTags || !username || !password}
                    >
                        {#if loadingTags}<Spinner />{/if}
                        Verify superuser and load tags
                    </Button>
                    {#if tags.length > 0}
                        <span class="text-sm text-muted-foreground">
                            Loaded {tags.length} tags.
                        </span>
                    {/if}
                </div>
                {#if tagError}
                    <p class="text-sm text-destructive">{tagError}</p>
                {/if}
                <div class="flex justify-between gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        onclick={previousStep}>Back</Button
                    >
                    <Button type="button" onclick={nextStep}>Next</Button>
                </div>
            </section>

            <section
                class:hidden={activeStep !== 'tags'}
                class="grid gap-4 border-t pt-6"
            >
                <div>
                    <h2 class="text-lg font-semibold">Paperless tags</h2>
                    <p class="text-sm text-muted-foreground">
                        Select tags by their readable Paperless names. Only the
                        inbox tag is required.
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="paperless_inbox_tag_id">Inbox tag</Label>
                        <select
                            id="paperless_inbox_tag_id"
                            name="paperless_inbox_tag_id"
                            required
                            bind:value={inboxTagId}
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">Select inbox tag</option>
                            {#each tags as tag (tag.id)}
                                <option value={String(tag.id)}
                                    >{tag.name} (#{tag.id})</option
                                >
                            {/each}
                        </select>
                        <InputError message={errors.paperless_inbox_tag_id} />
                    </div>

                    <div class="grid gap-2">
                        <Label for="paperless_processed_tag_id">
                            Processed tag
                        </Label>
                        <select
                            id="paperless_processed_tag_id"
                            name="paperless_processed_tag_id"
                            bind:value={processedTagId}
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">None</option>
                            {#each tags as tag (tag.id)}
                                <option value={String(tag.id)}
                                    >{tag.name} (#{tag.id})</option
                                >
                            {/each}
                        </select>
                        <InputError
                            message={errors.paperless_processed_tag_id}
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="ocr_requested_tag_id">
                            OCR requested tag
                        </Label>
                        <select
                            id="ocr_requested_tag_id"
                            name="ocr_requested_tag_id"
                            bind:value={ocrRequestedTagId}
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">None</option>
                            {#each tags as tag (tag.id)}
                                <option value={String(tag.id)}
                                    >{tag.name} (#{tag.id})</option
                                >
                            {/each}
                        </select>
                        <InputError message={errors.ocr_requested_tag_id} />
                    </div>
                </div>

                <div class="flex justify-between gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        onclick={previousStep}>Back</Button
                    >
                    <Button
                        type="submit"
                        disabled={processing || tags.length === 0}
                    >
                        {#if processing}<Spinner />{/if}
                        Complete setup and configure AI providers
                    </Button>
                </div>
            </section>
        {/snippet}
    </Form>
</div>
