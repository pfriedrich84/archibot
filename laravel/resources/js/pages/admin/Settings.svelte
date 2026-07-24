<script module lang="ts">
    import { edit as editAdminSettings } from '@/routes/admin/settings';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Admin settings',
                href: editAdminSettings(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Checkbox } from '@/components/ui/checkbox';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { update } from '@/routes/admin/settings';

    type Setting = {
        key: string;
        input_name: string;
        label: string;
        type:
            | 'text'
            | 'url'
            | 'number'
            | 'password'
            | 'bool'
            | 'select'
            | 'textarea';
        options: string[];
        required: boolean;
        sensitive: boolean;
        read_only: boolean;
        has_value: boolean;
        value: string;
        help: string | null;
        min: string | null;
        max: string | null;
        step: string | null;
        entity: string | null;
    };

    type SettingGroup = {
        name: string;
        slug: string;
        settings: Setting[];
    };

    type SettingsSection = {
        name: string;
        slug: string;
        count: number;
        href: string;
    };

    type PaperlessTagOption = {
        id: number;
        label: string;
    };

    type Prompt = {
        key: string;
        label: string;
        description: string;
        content: string;
        has_override: boolean;
        update_url: string;
        reset_url: string;
    };

    let {
        groups,
        sections,
        activeSection,
        prompts,
        paperlessTagOptions,
        webhookDevelopmentBypassActive = false,
        aiModelActions,
        paperlessAiState,
    }: {
        groups: SettingGroup[];
        sections: SettingsSection[];
        activeSection: string;
        prompts: Prompt[];
        paperlessTagOptions: PaperlessTagOption[];
        webhookDevelopmentBypassActive?: boolean;
        aiModelActions: { discover: string; validate: string };
        paperlessAiState: {
            desired: Record<string, unknown>;
            remote: Record<string, unknown> | null;
            drift_fields: Record<
                string,
                { desired: unknown; remote: unknown }
            >;
            sync_status: string;
            last_remote_read_at: string | null;
            error?: string;
        };
    } = $props();

    let aiModelLoading = $state(false);
    let aiModelError = $state('');
    let aiModelItems = $state<string[]>([]);
    let aiModelValidationLoading = $state(false);
    let aiModelValidationResults = $state<Record<string, string>>({});
    let aiModelValidationError = $state('');
    let aiModelDiscoveryMessage = $state('');
    let aiModelProvider = $state<{
        type: string;
        base_url: string;
    } | null>(null);
    let paperlessAiRefreshing = $state(false);
    let paperlessAiRefreshError = $state('');

    const csrfToken = () =>
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

    const modelSettingInputNames = new Set([
        'classification_model',
        'embedding_model',
        'ocr_text_model',
        'ocr_vision_model',
        'classification_judge_model',
    ]);
    const modelRoleInputNames: Record<string, string> = {
        classification: 'classification_model',
        embedding: 'embedding_model',
        ocr_text: 'ocr_text_model',
        ocr_vision: 'ocr_vision_model',
        judge: 'classification_judge_model',
    };

    const providerConfigInputNames = new Set([
        'llm_provider',
        'ollama_url',
        'llm_openai_api_key',
        'ollama_timeout_seconds',
        'ollama_model_swap_delay',
    ]);

    const isModelSetting = (setting: Setting) =>
        modelSettingInputNames.has(setting.input_name);

    const isProviderConfigSetting = (setting: Setting) =>
        providerConfigInputNames.has(setting.input_name);

    const isPaperlessTagSetting = (setting: Setting) =>
        setting.entity === 'paperless_tag';

    const paperlessTagLabel = (id: string) => {
        const option = paperlessTagOptions.find(
            (tag) => String(tag.id) === String(id),
        );

        if (option) {
            return option.label;
        }

        return id ? `Unknown Paperless tag (#${id})` : '';
    };

    const settingValue = (name: string) => {
        const element = document.querySelector<
            HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
        >(`[name="${name}"]`);

        return element?.value ?? '';
    };

    async function loadAiModels() {
        aiModelLoading = true;
        aiModelError = '';
        aiModelItems = [];
        aiModelProvider = null;
        aiModelDiscoveryMessage = '';

        try {
            const response = await fetch(aiModelActions.discover, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    llm_provider: settingValue('llm_provider'),
                    ollama_url: settingValue('ollama_url'),
                    openai_api_key: settingValue('llm_openai_api_key'),
                }),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                const errors = data?.errors ?? {};
                const firstError = Object.values(errors).flat()[0];

                throw new Error(
                    typeof firstError === 'string'
                        ? firstError
                        : (data?.message ?? 'Could not load models.'),
                );
            }

            aiModelItems = data.items ?? [];
            aiModelProvider = data.provider ?? null;
            aiModelDiscoveryMessage = data.discovery?.message ?? '';
        } catch (error) {
            aiModelError =
                error instanceof Error ? error.message : String(error);
        } finally {
            aiModelLoading = false;
        }
    }

    const paperlessAiStateUrl = () =>
        aiModelActions.discover.replace(/\/ai-models$/, '/paperless-ai-state');

    async function refreshPaperlessAiState() {
        paperlessAiRefreshing = true;
        paperlessAiRefreshError = '';

        try {
            const response = await fetch(paperlessAiStateUrl(), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data?.message ?? 'Could not refresh Paperless AI state.');
            }

            paperlessAiState = data;
        } catch (error) {
            paperlessAiRefreshError =
                error instanceof Error ? error.message : String(error);
        } finally {
            paperlessAiRefreshing = false;
        }
    }

    async function validateConfiguredAiModels() {
        aiModelValidationLoading = true;
        aiModelValidationResults = {};
        aiModelValidationError = '';

        try {
            const configuredModels = Object.entries(modelRoleInputNames)
                .map(([role, inputName]) => ({
                    role,
                    model: settingValue(inputName).trim(),
                }))
                .filter(({ model }) => model !== '');

            if (configuredModels.length === 0) {
                throw new Error(
                    'Configure at least one model before validation.',
                );
            }

            for (const configured of configuredModels) {
                const response = await fetch(aiModelActions.validate, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({
                        model_id: configured.model,
                        role: configured.role,
                        llm_provider: settingValue('llm_provider'),
                        ollama_url: settingValue('ollama_url'),
                        openai_api_key: settingValue('llm_openai_api_key'),
                    }),
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const firstError = Object.values(
                        data?.errors ?? {},
                    ).flat()[0];

                    throw new Error(
                        `${configured.role}: ${
                            typeof firstError === 'string'
                                ? firstError
                                : (data?.message ?? 'Model validation failed.')
                        }`,
                    );
                }

                aiModelValidationResults = {
                    ...aiModelValidationResults,
                    [configured.role]: `${configured.model} validated`,
                };
            }
        } catch (error) {
            aiModelValidationError =
                error instanceof Error ? error.message : String(error);
        } finally {
            aiModelValidationLoading = false;
        }
    }
</script>

<AppHead title="Admin settings" />

<div class="space-y-6">
    <Heading
        title="Admin settings"
        description="Manage global ArchiBot settings. Only Paperless superusers can access this page. Secrets are write-only after saving."
    />

    <aside
        class="max-w-3xl rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm"
    >
        <strong>Conversational document lookup is disabled for every user.</strong>
        Its page, routes, provider setting, prompt editor, and global MCP document lookup
        tools are unavailable.
        <a
            class="underline"
            href="https://github.com/pfriedrich84/archibot/issues/221"
            >Issue #221</a
        >
        is the only redesign and re-enable track.
    </aside>

    <aside
        class="max-w-3xl rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm"
    >
        <strong>Confidence auto-commit is temporarily suspended.</strong>
        Under ADR-0018, model or judge confidence cannot authorize Paperless writes.
        The effective threshold is fixed at 0 and every classification remains pending
        until an authorized user accepts it manually.
    </aside>

    {#if webhookDevelopmentBypassActive}
        <aside
            class="max-w-3xl rounded-xl border border-red-500/50 bg-red-500/10 p-4 text-sm"
            role="alert"
        >
            <strong
                >Development-only webhook authentication bypass is active.</strong
            >
            Webhook requests are accepted without a shared secret in this local/development
            environment. Disable
            <code>PAPERLESS_WEBHOOK_DEVELOPMENT_BYPASS</code> before using this instance
            outside isolated development.
        </aside>
    {/if}

    <nav class="flex flex-wrap gap-2" aria-label="Admin settings sections">
        {#each sections as section (section.slug)}
            <a
                href={section.href}
                class="rounded-full border px-3 py-1.5 text-sm transition hover:bg-muted {activeSection ===
                section.slug
                    ? 'border-primary bg-primary text-primary-foreground hover:bg-primary/90'
                    : 'bg-background'}"
                aria-current={activeSection === section.slug
                    ? 'page'
                    : undefined}
            >
                {section.name}
                <span class="ml-1 opacity-70">({section.count})</span>
            </a>
        {/each}
    </nav>

    {#if activeSection === 'ai-provider'}
        <section class="grid max-w-3xl gap-4 rounded-xl border p-6">
            <div>
                <h2 class="text-lg font-semibold">AI provider configuration</h2>
                <p class="text-sm text-muted-foreground">
                    ArchiBot uses one provider endpoint for the whole
                    installation. Configure its type, URL and optional API key
                    below, then load the available model IDs. You may also enter
                    a model ID manually in any model field.
                </p>
            </div>

            <Form {...update.form()} class="grid gap-4">
                {#snippet children({ errors, processing })}
                    {#each groups as group (group.name)}
                        {#if group.slug === 'ai-provider'}
                            {#each group.settings as setting (setting.key)}
                                {#if isProviderConfigSetting(setting)}
                                    <input
                                        type="hidden"
                                        name="__settings_keys[]"
                                        value={setting.key}
                                    />
                                    <div class="grid gap-2">
                                        {#if setting.type === 'select'}
                                            <Label for={setting.input_name}
                                                >{setting.label}</Label
                                            >
                                            <select
                                                id={setting.input_name}
                                                name={setting.input_name}
                                                required={setting.required}
                                                class="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs"
                                            >
                                                {#each setting.options as option (option)}
                                                    <option
                                                        value={option}
                                                        selected={setting.value ===
                                                            option}
                                                    >
                                                        {option}
                                                    </option>
                                                {/each}
                                            </select>
                                        {:else if setting.type === 'number'}
                                            <Label for={setting.input_name}
                                                >{setting.label}</Label
                                            >
                                            <Input
                                                id={setting.input_name}
                                                name={setting.input_name}
                                                type="number"
                                                required={setting.required}
                                                value={setting.value}
                                                min={setting.min ?? undefined}
                                                max={setting.max ?? undefined}
                                                step={setting.step ?? 'any'}
                                            />
                                        {:else}
                                            <Label for={setting.input_name}
                                                >{setting.label}</Label
                                            >
                                            <Input
                                                id={setting.input_name}
                                                name={setting.input_name}
                                                type={setting.sensitive
                                                    ? 'password'
                                                    : setting.type}
                                                required={setting.required &&
                                                    !setting.sensitive}
                                                value={setting.sensitive
                                                    ? ''
                                                    : setting.value}
                                                placeholder={setting.sensitive &&
                                                setting.has_value
                                                    ? 'Current value saved — leave blank to keep'
                                                    : undefined}
                                            />
                                        {/if}

                                        {#if setting.help || setting.sensitive}
                                            <p
                                                class="text-sm text-muted-foreground"
                                            >
                                                {setting.help ?? ''}
                                                {#if setting.sensitive && setting.has_value}
                                                    {setting.help ? ' ' : ''}A
                                                    value is already saved and
                                                    masked.
                                                {/if}
                                            </p>
                                        {/if}
                                        <InputError
                                            message={errors[setting.input_name]}
                                        />
                                    </div>
                                {/if}
                            {/each}
                        {/if}
                    {/each}

                    <div class="flex flex-wrap gap-3 pt-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onclick={loadAiModels}
                            disabled={aiModelLoading || processing}
                        >
                            {#if aiModelLoading}<Spinner />{/if}
                            Test connection and load models
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            onclick={validateConfiguredAiModels}
                            disabled={aiModelValidationLoading || processing}
                        >
                            {#if aiModelValidationLoading}<Spinner />{/if}
                            Validate configured models
                        </Button>
                    </div>

                    {#if aiModelError}
                        <p class="text-sm text-destructive" role="alert">
                            Connection failure: {aiModelError}
                        </p>
                    {/if}
                    {#if aiModelDiscoveryMessage}
                        <p
                            class="text-sm text-amber-700 dark:text-amber-400"
                            role="status"
                        >
                            {aiModelDiscoveryMessage}
                        </p>
                    {/if}
                    {#if aiModelValidationError}
                        <p class="text-sm text-destructive" role="alert">
                            Validation failure: {aiModelValidationError}
                        </p>
                    {/if}

                    {#if aiModelProvider}
                        <div class="rounded-md border bg-muted/30 p-3 text-sm">
                            Loaded {aiModelItems.length} models from the configured
                            {aiModelProvider.type} endpoint ({aiModelProvider.base_url}).
                            Document or OCR content may leave your machine when
                            this is a remote provider.
                        </div>
                    {/if}

                    {#if Object.keys(aiModelValidationResults).length > 0}
                        <ul
                            class="grid gap-1 text-sm text-green-700 dark:text-green-400"
                            role="status"
                        >
                            {#each Object.entries(aiModelValidationResults) as [role, result] (role)}
                                <li><strong>{role}:</strong> {result}</li>
                            {/each}
                        </ul>
                    {/if}

                    {#if aiModelItems.length > 0}
                        <datalist id="ai-loaded-models">
                            {#each aiModelItems as model (model)}
                                <option value={model}></option>
                            {/each}
                        </datalist>
                        <p class="text-xs text-muted-foreground">
                            {aiModelItems.length} model IDs are now available as suggestions
                            in the model fields below.
                        </p>
                    {/if}
                {/snippet}
            </Form>
        </section>
    {/if}

    {#if activeSection === 'paperless-ai'}
        <section class="grid max-w-4xl gap-5 rounded-xl border p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">
                        Paperless AI managed configuration
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        ArchiBot is the desired-state source. Drift is detected and shown here, but not overwritten automatically.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="secondary"
                    onclick={refreshPaperlessAiState}
                    disabled={paperlessAiRefreshing}
                >
                    {#if paperlessAiRefreshing}<Spinner />{/if}
                    Refresh remote state
                </Button>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-lg border p-4">
                    <div class="text-sm text-muted-foreground">Sync status</div>
                    <div class="mt-1 font-medium">{paperlessAiState.sync_status}</div>
                    {#if paperlessAiState.last_remote_read_at}
                        <div class="mt-1 text-xs text-muted-foreground">
                            Last remote read: {paperlessAiState.last_remote_read_at}
                        </div>
                    {/if}
                    {#if paperlessAiState.error}
                        <div class="mt-2 text-sm text-destructive">
                            {paperlessAiState.error}
                        </div>
                    {/if}
                    {#if paperlessAiRefreshError}
                        <div class="mt-2 text-sm text-destructive">
                            {paperlessAiRefreshError}
                        </div>
                    {/if}
                </div>
                <div class="rounded-lg border p-4">
                    <div class="text-sm text-muted-foreground">Detected drift fields</div>
                    <div class="mt-1 text-3xl font-semibold">
                        {Object.keys(paperlessAiState.drift_fields ?? {}).length}
                    </div>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-lg border p-4">
                    <h3 class="font-medium">Desired state</h3>
                    <dl class="mt-3 grid gap-2 text-sm">
                        {#each Object.entries(paperlessAiState.desired ?? {}) as [key, value] (key)}
                            <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-3">
                                <dt class="text-muted-foreground">{key}</dt>
                                <dd class="break-all">{String(value)}</dd>
                            </div>
                        {/each}
                    </dl>
                </div>
                <div class="rounded-lg border p-4">
                    <h3 class="font-medium">Remote state</h3>
                    {#if paperlessAiState.remote}
                        <dl class="mt-3 grid gap-2 text-sm">
                            {#each Object.entries(paperlessAiState.remote ?? {}) as [key, value] (key)}
                                <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-3">
                                    <dt class="text-muted-foreground">{key}</dt>
                                    <dd class="break-all">{String(value)}</dd>
                                </div>
                            {/each}
                        </dl>
                    {:else}
                        <p class="mt-3 text-sm text-muted-foreground">
                            No remote state available.
                        </p>
                    {/if}
                </div>
            </div>

            <div class="rounded-lg border p-4">
                <h3 class="font-medium">Drift details</h3>
                {#if Object.keys(paperlessAiState.drift_fields ?? {}).length > 0}
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="py-2 pr-4">Field</th>
                                    <th class="py-2 pr-4">Desired</th>
                                    <th class="py-2">Remote</th>
                                </tr>
                            </thead>
                            <tbody>
                                {#each Object.entries(paperlessAiState.drift_fields ?? {}) as [field, values] (field)}
                                    <tr class="border-b align-top last:border-b-0">
                                        <td class="py-2 pr-4 font-medium">{field}</td>
                                        <td class="py-2 pr-4 break-all">{String(values.desired)}</td>
                                        <td class="py-2 break-all">{String(values.remote)}</td>
                                    </tr>
                                {/each}
                            </tbody>
                        </table>
                    </div>
                {:else}
                    <p class="mt-3 text-sm text-muted-foreground">
                        No drift detected.
                    </p>
                {/if}
            </div>
        </section>
    {/if}

    {#if groups.length > 0}
        <Form {...update.form()} class="grid gap-6">
            {#snippet children({ errors, processing, recentlySuccessful })}
                {#each groups as group (group.name)}
                    {#if !(activeSection === 'ai-provider' && group.slug === 'ai-provider')}
                        <section
                            class="grid max-w-3xl gap-5 rounded-xl border p-6"
                        >
                            <div>
                                <h2 class="text-lg font-semibold">
                                    {group.name}
                                </h2>
                            </div>

                            {#each group.settings as setting (setting.key)}
                                <input
                                    type="hidden"
                                    name="__settings_keys[]"
                                    value={setting.key}
                                />
                                <div class="grid gap-2">
                                    {#if setting.type === 'bool'}
                                        <Label
                                            for={setting.input_name}
                                            class="flex items-center gap-3"
                                        >
                                            <Checkbox
                                                id={setting.input_name}
                                                name={setting.input_name}
                                                value="1"
                                                checked={setting.value ===
                                                    '1' ||
                                                    setting.value === 'true'}
                                            />
                                            <span>{setting.label}</span>
                                        </Label>
                                    {:else if isPaperlessTagSetting(setting) && paperlessTagOptions.length > 0}
                                        <Label for={setting.input_name}
                                            >{setting.label}</Label
                                        >
                                        <select
                                            id={setting.input_name}
                                            name={setting.input_name}
                                            required={setting.required}
                                            class="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs"
                                        >
                                            {#if !setting.required}
                                                <option value="">None</option>
                                            {/if}
                                            {#if setting.value && !paperlessTagOptions.some((tag) => String(tag.id) === setting.value)}
                                                <option
                                                    value={setting.value}
                                                    selected
                                                >
                                                    {paperlessTagLabel(
                                                        setting.value,
                                                    )}
                                                </option>
                                            {/if}
                                            {#each paperlessTagOptions as tag (tag.id)}
                                                <option
                                                    value={String(tag.id)}
                                                    selected={setting.value ===
                                                        String(tag.id)}
                                                >
                                                    {tag.label}
                                                </option>
                                            {/each}
                                        </select>
                                    {:else if setting.type === 'select'}
                                        <Label for={setting.input_name}
                                            >{setting.label}</Label
                                        >
                                        <select
                                            id={setting.input_name}
                                            name={setting.input_name}
                                            required={setting.required}
                                            class="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs"
                                        >
                                            {#each setting.options as option (option)}
                                                <option
                                                    value={option}
                                                    selected={setting.value ===
                                                        option}
                                                >
                                                    {option}
                                                </option>
                                            {/each}
                                        </select>
                                    {:else if setting.type === 'textarea'}
                                        <Label for={setting.input_name}
                                            >{setting.label}</Label
                                        >
                                        <textarea
                                            id={setting.input_name}
                                            name={setting.input_name}
                                            required={setting.required}
                                            rows="8"
                                            class="min-h-32 rounded-md border bg-background p-3 font-mono text-sm"
                                            >{setting.value}</textarea
                                        >
                                    {:else}
                                        <Label for={setting.input_name}
                                            >{setting.label}</Label
                                        >
                                        <Input
                                            id={setting.input_name}
                                            name={setting.input_name}
                                            type={setting.sensitive
                                                ? 'password'
                                                : setting.type}
                                            required={setting.required &&
                                                !setting.sensitive}
                                            value={setting.sensitive
                                                ? ''
                                                : setting.value}
                                            min={setting.min ?? undefined}
                                            max={setting.max ?? undefined}
                                            step={setting.step ??
                                                (setting.type === 'number'
                                                    ? 'any'
                                                    : undefined)}
                                            list={isModelSetting(setting)
                                                ? 'ai-loaded-models'
                                                : undefined}
                                            placeholder={setting.sensitive &&
                                            setting.has_value
                                                ? 'Current value saved — leave blank to keep'
                                                : undefined}
                                            disabled={setting.read_only}
                                        />
                                    {/if}

                                    {#if setting.help || setting.sensitive}
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {setting.help ?? ''}
                                            {#if setting.sensitive && setting.has_value}
                                                {setting.help ? ' ' : ''}A value
                                                is already saved and masked.
                                            {/if}
                                        </p>
                                    {/if}
                                    <InputError
                                        message={errors[setting.input_name]}
                                    />
                                </div>
                            {/each}
                        </section>
                    {/if}
                {/each}

                {#if activeSection === 'ai-provider'}
                    <section class="grid max-w-3xl gap-5 rounded-xl border p-6">
                        <div>
                            <h2 class="text-lg font-semibold">
                                Role model selection
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Each AI role uses the shared provider endpoint
                                above with its own model ID. Select from
                                discovered models or enter a model ID manually.
                            </p>
                        </div>

                        {#each [{ role: 'classification', label: 'Classification model', key: 'classification.model', input: 'classification_model', help: 'Model used for document classification and tag suggestions.' }, { role: 'embedding', label: 'Embedding model', key: 'embedding.model', input: 'embedding_model', help: 'Model used for vector embeddings and semantic search.' }, { role: 'ocr_text', label: 'OCR text model', key: 'ocr.text_model', input: 'ocr_text_model', help: 'Model used for text-only OCR correction.' }, { role: 'ocr_vision', label: 'OCR vision model', key: 'ocr.vision_model', input: 'ocr_vision_model', help: 'Model used for vision-based OCR correction.' }, { role: 'judge', label: 'Judge model', key: 'classification.judge_model', input: 'classification_judge_model', help: 'Model used for LLM-as-judge verification of uncertain classifications.' }] as modelConfig (modelConfig.key)}
                            {@const modelSetting = groups
                                .flatMap((group) => group.settings)
                                .find(
                                    (setting) =>
                                        setting.key === modelConfig.key,
                                )}
                            {#if modelSetting}
                                <input
                                    type="hidden"
                                    name="__settings_keys[]"
                                    value={modelConfig.key}
                                />
                                <div class="grid gap-2">
                                    <Label for={modelConfig.input}
                                        >{modelConfig.label}</Label
                                    >
                                    <Input
                                        id={modelConfig.input}
                                        name={modelConfig.input}
                                        type="text"
                                        value={modelSetting.value}
                                        list="ai-loaded-models"
                                        class="font-mono text-sm"
                                    />
                                    <p class="text-sm text-muted-foreground">
                                        {modelConfig.help}
                                    </p>
                                    <InputError
                                        message={errors[modelConfig.input]}
                                    />
                                </div>
                            {/if}
                        {/each}
                    </section>
                {/if}

                <div class="flex items-center gap-4">
                    <Button
                        type="submit"
                        disabled={processing}
                        data-test="save-admin-settings"
                    >
                        {#if processing}<Spinner />{/if}
                        Save settings
                    </Button>

                    {#if recentlySuccessful}
                        <p class="text-sm text-green-600">Settings saved.</p>
                    {/if}
                </div>
            {/snippet}
        </Form>
    {/if}

    {#if activeSection === 'prompts'}
        <section class="grid gap-5 rounded-xl border p-6">
            <div>
                <h2 class="text-lg font-semibold">System prompts</h2>
                <p class="text-sm text-muted-foreground">
                    Edit Python prompt overrides stored in the shared data
                    directory. Resetting a prompt removes the override and falls
                    back to the bundled default.
                </p>
            </div>

            {#each prompts as prompt (prompt.key)}
                <article class="grid gap-3 rounded-lg border p-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div>
                            <h3 class="font-medium">{prompt.label}</h3>
                            <p class="text-sm text-muted-foreground">
                                {prompt.description}
                            </p>
                        </div>
                        <span class="rounded-full bg-muted px-2 py-1 text-xs">
                            {prompt.has_override
                                ? 'Custom override'
                                : 'Bundled default'}
                        </span>
                    </div>

                    <Form
                        method="patch"
                        action={prompt.update_url}
                        class="grid gap-3"
                    >
                        {#snippet children({
                            errors,
                            processing,
                            recentlySuccessful,
                        })}
                            <textarea
                                name="content"
                                rows="8"
                                maxlength="80000"
                                class="min-h-48 rounded-md border bg-background p-3 font-mono text-sm"
                                placeholder="Leave empty only if you want to write an empty override."
                                >{prompt.content}</textarea
                            >
                            <InputError message={errors.content} />
                            <div class="flex flex-wrap items-center gap-3">
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    {#if processing}<Spinner />{/if}
                                    Save prompt
                                </Button>
                                {#if recentlySuccessful}
                                    <span class="text-sm text-green-600"
                                        >Prompt saved.</span
                                    >
                                {/if}
                            </div>
                        {/snippet}
                    </Form>

                    {#if prompt.has_override}
                        <Form
                            method="delete"
                            action={prompt.reset_url}
                            onsubmit={(event) => {
                                if (
                                    !confirm(
                                        `Reset the ${prompt.label} override? The bundled default will take effect immediately.`,
                                    )
                                ) {
                                    event.preventDefault();
                                }
                            }}
                        >
                            {#snippet children({ processing })}
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Reset to bundled default
                                </Button>
                            {/snippet}
                        </Form>
                    {/if}
                </article>
            {/each}
        </section>
    {/if}
</div>
