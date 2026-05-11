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
        has_value: boolean;
        value: string;
        help: string | null;
        min: string | null;
        max: string | null;
        step: string | null;
    };

    type SettingGroup = {
        name: string;
        settings: Setting[];
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

    let { groups, prompts }: { groups: SettingGroup[]; prompts: Prompt[] } =
        $props();
</script>

<AppHead title="Admin settings" />

<div class="space-y-6">
    <Heading
        title="Admin settings"
        description="Manage global ArchiBot settings. Only Paperless superusers can access this page. Secrets are write-only after saving."
    />

    <Form {...update.form()} class="grid gap-6">
        {#snippet children({ errors, processing, recentlySuccessful })}
            {#each groups as group (group.name)}
                <section class="grid max-w-3xl gap-5 rounded-xl border p-6">
                    <div>
                        <h2 class="text-lg font-semibold">{group.name}</h2>
                    </div>

                    {#each group.settings as setting (setting.key)}
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
                                        checked={setting.value === '1' ||
                                            setting.value === 'true'}
                                    />
                                    <span>{setting.label}</span>
                                </Label>
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
                                            selected={setting.value === option}
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
                                    placeholder={setting.sensitive &&
                                    setting.has_value
                                        ? 'Current value saved — leave blank to keep'
                                        : undefined}
                                />
                            {/if}

                            {#if setting.help || setting.sensitive}
                                <p class="text-sm text-muted-foreground">
                                    {setting.help ?? ''}
                                    {#if setting.sensitive && setting.has_value}
                                        {setting.help ? ' ' : ''}A value is
                                        already saved and masked.
                                    {/if}
                                </p>
                            {/if}
                            <InputError message={errors[setting.input_name]} />
                        </div>
                    {/each}
                </section>
            {/each}

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
                <div class="flex flex-wrap items-center justify-between gap-3">
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
                    <Form method="delete" action={prompt.reset_url}>
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
</div>
