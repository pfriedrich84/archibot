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
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { update } from '@/routes/admin/settings';

    let {
        settings,
    }: {
        settings: {
            paperless_url: string;
            audit_retention_days: number;
        };
    } = $props();
</script>

<AppHead title="Admin settings" />

<div class="space-y-6">
    <Heading
        title="Admin settings"
        description="Manage global ArchiBot settings. Only Paperless superusers can access this page."
    />

    <Form {...update.form()} class="grid max-w-2xl gap-6 rounded-xl border p-6">
        {#snippet children({ errors, processing, recentlySuccessful })}
            <div class="grid gap-2">
                <Label for="paperless_url">Paperless-NGX URL</Label>
                <Input
                    id="paperless_url"
                    name="paperless_url"
                    type="url"
                    required
                    value={settings.paperless_url}
                    placeholder="https://paperless.example.test"
                />
                <p class="text-sm text-muted-foreground">
                    This is the single global Paperless server configured during
                    first-run setup. Changing it is audit-logged.
                </p>
                <InputError message={errors.paperless_url} />
            </div>

            <div class="grid gap-2">
                <Label for="audit_retention_days">Audit retention days</Label>
                <Input
                    id="audit_retention_days"
                    name="audit_retention_days"
                    type="number"
                    min="1"
                    max="365"
                    required
                    value={settings.audit_retention_days}
                />
                <p class="text-sm text-muted-foreground">
                    Default target is 7 days. A pruning command/schedule will be
                    added with the broader worker/settings milestone.
                </p>
                <InputError message={errors.audit_retention_days} />
            </div>

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
