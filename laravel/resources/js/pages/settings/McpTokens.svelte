<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'MCP tokens',
                href: '/settings/mcp-tokens',
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';

    type McpToken = {
        id: number;
        name: string;
        last_used_at: string | null;
        revoked_at: string | null;
        created_at: string | null;
    };

    let {
        tokens,
        createdToken,
    }: {
        tokens: McpToken[];
        createdToken: string | null;
    } = $props();
</script>

<AppHead title="MCP tokens" />

<div class="space-y-6">
    <Heading
        title="MCP tokens"
        description="Create long-lived MCP tokens linked to your Paperless-authenticated ArchiBot user. Raw tokens are shown once and stored hashed only."
    />

    {#if createdToken}
        <section
            class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-50"
        >
            <div class="font-medium">
                Copy this token now. It will not be shown again.
            </div>
            <code
                class="mt-3 block overflow-x-auto rounded bg-background p-3 text-xs text-foreground"
                >{createdToken}</code
            >
        </section>
    {/if}

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Create token</h2>
        <Form
            method="post"
            action="/settings/mcp-tokens"
            class="flex flex-wrap gap-3"
        >
            {#snippet children({ errors, processing })}
                <div class="min-w-64 flex-1">
                    <label for="name" class="mb-1 block text-sm font-medium"
                        >Token name</label
                    >
                    <input
                        id="name"
                        name="name"
                        required
                        maxlength="120"
                        class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                        placeholder="Laptop Claude Desktop"
                    />
                    {#if errors.name}
                        <div class="mt-1 text-xs text-destructive">
                            {errors.name}
                        </div>
                    {/if}
                </div>
                <div class="flex items-end">
                    <Button type="submit" disabled={processing}
                        >Create token</Button
                    >
                </div>
            {/snippet}
        </Form>
    </section>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 font-medium">Existing tokens</div>
        {#each tokens as token (token.id)}
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b p-4 text-sm last:border-b-0"
            >
                <div>
                    <div class="font-medium">{token.name}</div>
                    <div class="text-xs text-muted-foreground">
                        Created {token.created_at ?? '—'} · Last used {token.last_used_at ??
                            'never'}
                        {#if token.revoked_at}
                            · Revoked {token.revoked_at}
                        {/if}
                    </div>
                </div>
                {#if !token.revoked_at}
                    <Form
                        method="delete"
                        action={`/settings/mcp-tokens/${token.id}`}
                    >
                        {#snippet children({ processing })}
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}>Revoke</Button
                            >
                        {/snippet}
                    </Form>
                {:else}
                    <span class="rounded-full bg-muted px-3 py-1 text-xs"
                        >Revoked</span
                    >
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No MCP tokens yet.
            </div>
        {/each}
    </section>
</div>
