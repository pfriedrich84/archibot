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
        (() => defaults.classificationModel ?? 'gemma4:e4b')(),
    );
    let embeddingModel = $state(
        (() => defaults.embeddingModel ?? 'qwen3-embedding:4b')(),
    );
    let ocrTextModel = $state((() => defaults.ocrTextModel ?? 'qwen3:4b')());
    let judgeModel = $state((() => defaults.judgeModel ?? 'qwen3:4b')());
    let ocrMode = $state('off');

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
            classificationModel ||= models[0] ?? '';
            embeddingModel ||= models[0] ?? '';
            ocrTextModel ||= models[0] ?? '';
            judgeModel ||= models[0] ?? '';
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
        class="grid gap-6 rounded-xl border bg-card p-6 shadow-sm"
    >
        {#snippet children({ errors, processing })}
            {#if requiresResetToken}
                <section class="grid gap-2">
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
                </section>
            {/if}

            <section class="grid gap-4">
                <div>
                    <h2 class="text-lg font-semibold">1. Paperless-NGX</h2>
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
            </section>

            <section class="grid gap-4 border-t pt-6">
                <div>
                    <h2 class="text-lg font-semibold">2. Paperless tags</h2>
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
                                    >{tag.name}</option
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
                                    >{tag.name}</option
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
                                    >{tag.name}</option
                                >
                            {/each}
                        </select>
                        <InputError message={errors.ocr_requested_tag_id} />
                    </div>
                </div>
            </section>

            <section class="grid gap-4 border-t pt-6">
                <div>
                    <h2 class="text-lg font-semibold">3. AI provider</h2>
                    <p class="text-sm text-muted-foreground">
                        Use native Ollama or a local OpenAI-compatible endpoint
                        such as LiteLLM, LM Studio, vLLM, LocalAI, llama.cpp, or
                        Ollama /v1.
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
                            <option value="ollama">Ollama native</option>
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

                <datalist id="ollama-models">
                    {#each models as model (model)}
                        <option value={model}></option>
                    {/each}
                </datalist>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="classification_model">
                            Classification model
                        </Label>
                        <Input
                            id="classification_model"
                            name="classification_model"
                            required
                            list="ollama-models"
                            value={classificationModel}
                            oninput={(event) =>
                                (classificationModel =
                                    event.currentTarget.value)}
                            placeholder="model:tag"
                        />
                        <InputError message={errors.classification_model} />
                    </div>

                    <div class="grid gap-2">
                        <Label for="embedding_model">Embedding model</Label>
                        <Input
                            id="embedding_model"
                            name="embedding_model"
                            required
                            list="ollama-models"
                            value={embeddingModel}
                            oninput={(event) =>
                                (embeddingModel = event.currentTarget.value)}
                            placeholder="model:tag"
                        />
                        <InputError message={errors.embedding_model} />
                    </div>

                    <div class="grid gap-2">
                        <Label for="ocr_text_model">OCR text model</Label>
                        <Input
                            id="ocr_text_model"
                            name="ocr_text_model"
                            list="ollama-models"
                            value={ocrTextModel}
                            oninput={(event) =>
                                (ocrTextModel = event.currentTarget.value)}
                            placeholder="model:tag"
                        />
                        <InputError message={errors.ocr_text_model} />
                    </div>

                    <div class="grid gap-2">
                        <Label for="classification_judge_model">
                            Judge model
                        </Label>
                        <Input
                            id="classification_judge_model"
                            name="classification_judge_model"
                            list="ollama-models"
                            value={judgeModel}
                            oninput={(event) =>
                                (judgeModel = event.currentTarget.value)}
                            placeholder="model:tag"
                        />
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
            </section>

            <Button type="submit" disabled={processing} class="w-full">
                {#if processing}<Spinner />{/if}
                Complete setup
            </Button>
        {/snippet}
    </Form>
</div>
