<script lang="ts">
  import { Card, Button, Input, Label, Checkbox } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
</script>

<AppShell title="Anmeldung" subtitle="Platzhalter für die spätere Session-/Auth-UX. Der aktuelle Betrieb nutzt weiterhin HTTP Basic Auth vor der App.">
  {#snippet children()}
    <div class="mx-auto max-w-xl">
      <Card class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Auth Architecture</p>
        <h2 class="mt-2 text-2xl font-semibold text-white">Sign in</h2>
        <p class="mt-3 text-sm text-slate-400">
          Diese Seite dokumentiert die spätere Login-/Session-Struktur für den Flowbite-Svelte-Admin.
        </p>

        <div class="mt-6 space-y-4">
          <div>
            <Label for="username">Benutzername</Label>
            <Input id="username" placeholder="archibot-admin" disabled />
          </div>
          <div>
            <Label for="password">Passwort</Label>
            <Input id="password" type="password" placeholder="••••••••" disabled />
          </div>
          <div class="flex items-center gap-2">
            <Checkbox disabled />
            <span class="text-sm text-slate-400">Session merken</span>
          </div>
          <Button color="blue" disabled>Anmelden</Button>
        </div>
      </Card>
    </div>
  {/snippet}
</AppShell>
