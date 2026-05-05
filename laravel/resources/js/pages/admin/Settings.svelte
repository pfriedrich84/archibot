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
        type: 'text' | 'url' | 'number' | 'password' | 'bool' | 'select';
        options: string[];
        required: boolean;
        sensitive: boolean;
        has_value: boolean;
        value: string;
        help: string | null;
    };

    type SettingGroup = {
        name: string;
        settings: Setting[];
    };

    let { groups }: { groups: SettingGroup[] } = $props();
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
</div>
