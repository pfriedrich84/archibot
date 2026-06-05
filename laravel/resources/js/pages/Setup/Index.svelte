<script module lang="ts">
    export const layout = {
        title: 'ArchiBot setup',
        description:
            'Connect ArchiBot to Paperless-NGX, choose tags, and select AI provider models',
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

    type TagOption = { id: number; name: string };
    type SetupDefaults = {
        inboxTagId?: string;
        processedTagId?: string;
        ocrRequestedTagId?: string;
        classificationModel?: string;
        embeddingModel?: string;
        ocrTextModel?: string;
        judgeModel?: string;
    };

    let {
        requiresResetToken = false,
        paperlessUrl = '',
        llmProvider = 'ollama',
        ollamaUrl = 'http://ollama:11434',
        defaults = {},
    }: {
        requiresResetToken: boolean;
        paperlessUrl?: string;
        llmProvider?: string;
        ollamaUrl?: string;
        defaults?: SetupDefaults;
    } = $props();

    let paperless_url = $state((() => paperlessUrl)());
    let username = $state('');
    let password = $state('');
    let llm_provider = $state((() => llmProvider)());
    let ollama_url = $state((() => ollamaUrl)());
    let openai_api_key = $state('');
    let tags = $state<TagOption[]>([]);
    let models = $state<string[]>([]);
    let tagError = $state('');
    let modelError = $state('');
    let loadingTags = $state(false);
    let loadingModels = $state(false);
    let inboxTagId = $state((() => defaults.inboxTagId ?? '')());
    let processedTagId = $state((() => defaults.processedTagId ?? '')());
    let ocrRequestedTagId = $state((() => defaults.ocrRequestedTagId ?? '')());
    let classificationModel = $state(
        (() => defaults.classificationModel ?? '')(),
    );
    let embeddingModel = $state((() => defaults.embeddingModel ?? '')());
    let ocrTextModel = $state((() => defaults.ocrTextModel ?? '')());
    let judgeModel = $state((() => defaults.judgeModel ?? '')());
    let ocrMode = $state('off');

    type SetupStep =
        | 'reset-token'
        | 'paperless'
        | 'tags'
        | 'provider'
        | 'models';
    const setupSteps = $derived([
        ...(requiresResetToken
            ? [{ id: 'reset-token' as const, label: 'Reset token' }]
            : []),
        { id: 'paperless' as const, label: 'Paperless' },
        { id: 'tags' as const, label: 'Tags' },
        { id: 'provider' as const, label: 'AI provider' },
        { id: 'models' as const, label: 'Models' },
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

    function bestModel(current: string, kind: 'chat' | 'embedding') {
        if (current && models.includes(current)) {
            return current;
        }

        if (kind === 'embedding') {
            return (
                models.find((model) => /embed|embedding/i.test(model)) ??
                models[0] ??
                ''
            );
        }

        return (
            models.find((model) => !/embed|embedding/i.test(model)) ??
            models[0] ??
            ''
        );
    }

    const csrfToken = () =>
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

    async function postJson<T>(url: string, payload: Record<string, unknown>) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(payload),
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const errors = data?.errors ?? {};
            const firstError = Object.values(errors).flat()[0];

            throw new Error(
                typeof firstError === 'string'
                    ? firstError
                    : (data?.message ?? 'Connection failed.'),
            );
        }

        return data as T;
    }

    async function loadPaperlessTags() {
        tagError = '';
        loadingTags = true;

        try {
            const data = await postJson<{ items: TagOption[] }>(
                '/setup/paperless-tags',
                { paperless_url, username, password },
            );
            tags = data.items;
            inboxTagId ||= String(tags[0]?.id ?? '');
        } catch (error) {
            tagError = error instanceof Error ? error.message : String(error);
        } finally {
            loadingTags = false;
        }
    }

    async function loadOllamaModels() {
        modelError = '';
        loadingModels = true;

        try {
            const data = await postJson<{ items: string[] }>(
                '/setup/ollama-models',
                {
                    llm_provider,
                    ollama_url,
                    openai_api_key,
                },
            );
            models = data.items;
            classificationModel = bestModel(classificationModel, 'chat');
            embeddingModel = bestModel(embeddingModel, 'embedding');
            ocrTextModel = bestModel(ocrTextModel, 'chat');
            judgeModel = bestModel(judgeModel, 'chat');
        } catch (error) {
            modelError = error instanceof Error ? error.message : String(error);
        } finally {
            loadingModels = false;
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
            Connect Paperless-NGX, choose the Paperless tags ArchiBot should
            use, then connect your local AI provider and select installed
            models.
        </p>
    </div>

    <Form
        action="/setup"
        method="post"
        resetOnSuccess={['password', 'setup_token']}
        novalidate
        class="grid gap-6 rounded-xl border bg-card p-6 shadow-sm"
    >
        {#snippet children({ errors, processing })}
            <nav
                class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5"
                aria-label="Setup steps"
            >
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
            </nav>

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
                    <h2 class="text-lg font-semibold">Paperless-NGX</h2>
                    <p class="text-sm text-muted-foreground">
                        Use an active Paperless admin/superuser account. The URL
                        is prefilled from your environment when available.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="paperless_url">Paperless-NGX URL</Label>
                    <Input
                        id="paperless_url"
                        name="paperless_url"
                        type="url"
                        required
                        value={paperless_url}
                        oninput={(event) =>
                            (paperless_url = event.currentTarget.value)}
                        placeholder="https://paperless.example.test"
                    />
                    <InputError message={errors.paperless_url} />
                </div>

                <div class="grid gap-2 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="username">Paperless username</Label>
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

                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        onclick={loadPaperlessTags}
                        disabled={loadingTags ||
                            !paperless_url ||
                            !username ||
                            !password}
                    >
                        {#if loadingTags}<Spinner />{/if}
                        Connect and load Paperless tags
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
                        <Label for="ocr_requested_tag_id"
                            >OCR requested tag</Label
                        >
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
                    <Button type="button" onclick={nextStep}>Next</Button>
                </div>
            </section>

            <section
                class:hidden={activeStep !== 'provider'}
                class="grid gap-4 border-t pt-6"
            >
                <div>
                    <h2 class="text-lg font-semibold">AI provider</h2>
                    <p class="text-sm text-muted-foreground">
                        Use an Ollama-compatible endpoint or an OpenAI-compatible
                        /v1 endpoint.
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="llm_provider">Provider</Label>
                        <select
                            id="llm_provider"
                            name="llm_provider"
                            bind:value={llm_provider}
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="ollama">Ollama-compatible endpoint</option>
                            <option value="openai_compatible">
                                OpenAI-compatible endpoint
                            </option>
                        </select>
                        <InputError message={errors.llm_provider} />
                    </div>

                    <div class="grid gap-2">
                        <Label for="ollama_url">Base URL</Label>
                        <Input
                            id="ollama_url"
                            name="ollama_url"
                            type="url"
                            required
                            value={ollama_url}
                            oninput={(event) =>
                                (ollama_url = event.currentTarget.value)}
                            placeholder={llm_provider === 'openai_compatible'
                                ? 'http://localhost:11434/v1'
                                : 'http://ollama:11434'}
                        />
                        <InputError message={errors.ollama_url} />
                    </div>
                </div>

                {#if llm_provider === 'openai_compatible'}
                    <div class="grid gap-2">
                        <Label for="openai_api_key">
                            API key / bearer token (optional)
                        </Label>
                        <PasswordInput
                            id="openai_api_key"
                            name="openai_api_key"
                            value={openai_api_key}
                            oninput={(event) =>
                                (openai_api_key = event.currentTarget.value)}
                            autocomplete="off"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave empty for local endpoints that do not require
                            authentication.
                        </p>
                        <InputError message={errors.openai_api_key} />
                    </div>
                {/if}

                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        onclick={loadOllamaModels}
                        disabled={loadingModels || !ollama_url}
                    >
                        {#if loadingModels}<Spinner />{/if}
                        Connect and load models
                    </Button>
                    {#if models.length > 0}
                        <span class="text-sm text-muted-foreground">
                            Loaded {models.length} models.
                        </span>
                    {/if}
                </div>
                {#if modelError}
                    <p class="text-sm text-destructive">{modelError}</p>
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
                class:hidden={activeStep !== 'models'}
                class="grid gap-4 border-t pt-6"
            >
                <div>
                    <h2 class="text-lg font-semibold">Models</h2>
                    <p class="text-sm text-muted-foreground">
                        Choose from the models returned by your provider. If the
                        provider returns no models, go back to the AI provider
                        tab and check the base URL or token.
                    </p>
                    {#if models.length > 0}
                        <p class="mt-2 text-sm text-muted-foreground">
                            Loaded {models.length} models. Selected:
                            {classificationModel || 'none'} / {embeddingModel ||
                                'none'}.
                        </p>
                    {/if}
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="classification_model">
                            Classification model
                        </Label>
                        <select
                            id="classification_model"
                            name="classification_model"
                            required
                            bind:value={classificationModel}
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value=""
                                >{models.length > 0
                                    ? 'Select classification model'
                                    : 'Load models first'}</option
                            >
                            {#each models as model (model)}
                                <option value={model}>{model}</option>
                            {/each}
                        </select>
                        <InputError message={errors.classification_model} />
                    </div>

                    <div class="grid gap-2">
                        <Label for="embedding_model">Embedding model</Label>
                        <select
                            id="embedding_model"
                            name="embedding_model"
                            required
                            bind:value={embeddingModel}
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value=""
                                >{models.length > 0
                                    ? 'Select embedding model'
                                    : 'Load models first'}</option
                            >
                            {#each models as model (model)}
                                <option value={model}>{model}</option>
                            {/each}
                        </select>
                        <InputError message={errors.embedding_model} />
                    </div>

                    <div class="grid gap-2">
                        <Label for="ocr_text_model">OCR text model</Label>
                        <select
                            id="ocr_text_model"
                            name="ocr_text_model"
                            bind:value={ocrTextModel}
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value=""
                                >{models.length > 0
                                    ? 'None / select OCR text model'
                                    : 'Load models first'}</option
                            >
                            {#each models as model (model)}
                                <option value={model}>{model}</option>
                            {/each}
                        </select>
                        <InputError message={errors.ocr_text_model} />
                    </div>

                    <div class="grid gap-2">
                        <Label for="classification_judge_model">
                            Judge model
                        </Label>
                        <select
                            id="classification_judge_model"
                            name="classification_judge_model"
                            bind:value={judgeModel}
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value=""
                                >{models.length > 0
                                    ? 'None / select judge model'
                                    : 'Load models first'}</option
                            >
                            {#each models as model (model)}
                                <option value={model}>{model}</option>
                            {/each}
                        </select>
                        <InputError
                            message={errors.classification_judge_model}
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="ocr_mode">OCR correction mode</Label>
                    <select
                        id="ocr_mode"
                        name="ocr_mode"
                        bind:value={ocrMode}
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="off">Off</option>
                        <option value="text">Text-only</option>
                        <option value="vision_light">Vision light</option>
                        <option value="vision_full">Vision full</option>
                    </select>
                    <InputError message={errors.ocr_mode} />
                </div>

                <div class="flex justify-between gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        onclick={previousStep}>Back</Button
                    >
                    <Button type="submit" disabled={processing}>
                        {#if processing}<Spinner />{/if}
                        Complete setup
                    </Button>
                </div>
            </section>
        {/snippet}
    </Form>
</div>
