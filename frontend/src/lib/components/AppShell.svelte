<script lang="ts">
  import { Sidebar, SidebarBrand, SidebarDropdownItem, SidebarDropdownWrapper, SidebarItem, Navbar, NavBrand, Badge } from 'flowbite-svelte';
  import { base } from '$app/paths';
  import { page } from '$app/state';
  import { navItems } from '$lib/nav';

  let { title = 'Dashboard', subtitle = '', children } = $props<{
    title?: string;
    subtitle?: string;
    children: import('svelte').Snippet;
  }>();

  let locale = $state<'de' | 'en'>('de');

  function toggleLocale() {
    locale = locale === 'de' ? 'en' : 'de';
  }

  function navHref(path: string) {
    return path === '/' ? `${base}/` : `${base}${path}`;
  }

  function isActive(path: string) {
    const current = page.url.pathname;
    const expected = navHref(path);
    return path === '/' ? current === expected : current === expected || current.startsWith(`${expected}/`);
  }
</script>

<div class="min-h-screen bg-slate-950 text-slate-100">
  <div class="flex min-h-screen">
    <aside class="hidden w-80 border-r border-slate-800 bg-slate-900/80 xl:block">
      <Sidebar class="h-full border-none bg-transparent px-4 py-6">
        <SidebarBrand href={navHref('/')} class="px-2">
          <img src={`${base}/logo.png`} class="mr-3 h-10 w-10 rounded-xl bg-slate-800 p-1" alt="ArchiBot" />
          <div>
            <p class="text-lg font-semibold text-white">ArchiBot</p>
            <p class="text-xs text-slate-400">SvelteKit Admin Migration</p>
          </div>
        </SidebarBrand>

        <div class="mt-8 space-y-2">
          {#each navItems as item}
            <SidebarItem href={navHref(item.href)} active={isActive(item.href)} class="rounded-xl text-slate-200 hover:bg-slate-800/80">
              <span class="mr-3 text-base">{item.emoji}</span>
              <span>{item.label}</span>
            </SidebarItem>
          {/each}
        </div>

        <SidebarDropdownWrapper class="mt-6 rounded-2xl border border-slate-800 bg-slate-900/80 p-4">
          <div class="mb-2 flex items-center justify-between">
            <span class="text-sm font-medium text-slate-200">Migration Status</span>
            <Badge color="yellow">Preview</Badge>
          </div>
          <SidebarDropdownItem class="text-sm text-slate-400">
            Legacy-UI bleibt aktiv bis zur Parität.
          </SidebarDropdownItem>
          <SidebarDropdownItem class="text-sm text-slate-400">
            Neue API: /api/v1/*
          </SidebarDropdownItem>
        </SidebarDropdownWrapper>

        <div class="mt-6 rounded-2xl border border-emerald-500/20 bg-emerald-500/10 p-4 text-emerald-100">
          <p class="font-semibold">Observability-first</p>
          <p class="mt-1 text-sm text-emerald-50/80">Statusflächen, strukturierte Logs und klare Degradationspfade sind Teil des neuen Stacks.</p>
        </div>
      </Sidebar>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <Navbar class="sticky top-0 z-20 border-b border-slate-800 bg-slate-950/90 backdrop-blur">
        <NavBrand href={navHref('/')} class="gap-3 xl:hidden">
          <img src={`${base}/logo.png`} class="h-9 w-9 rounded-xl bg-slate-800 p-1" alt="ArchiBot" />
          <span class="self-center whitespace-nowrap text-xl font-semibold text-white">ArchiBot</span>
        </NavBrand>

        <div class="ms-auto flex items-center gap-3">
          <div class="hidden rounded-2xl border border-slate-800 bg-slate-900 px-4 py-2 md:block">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Admin Shell</p>
            <p class="text-sm text-slate-200">Flowbite Svelte · Dark default · Responsive</p>
          </div>
          <button
            type="button"
            class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-2 text-sm font-medium text-slate-200"
            onclick={toggleLocale}
          >
            {locale.toUpperCase()}
          </button>
        </div>
      </Navbar>

      <main class="flex-1 px-4 py-6 md:px-8 xl:px-10">
        <section class="surface-gradient rounded-3xl border border-slate-800 px-6 py-8 shadow-2xl shadow-slate-950/30">
          <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
              <p class="text-sm font-medium uppercase tracking-[0.2em] text-emerald-300">Migration Preview</p>
              <h1 class="mt-2 text-3xl font-semibold text-white">{title}</h1>
              {#if subtitle}
                <p class="mt-2 max-w-3xl text-sm text-slate-300">{subtitle}</p>
              {/if}
            </div>
            <div class="flex items-center gap-3">
              <Badge large color="green">Flowbite Svelte</Badge>
              <Badge large color="purple">SvelteKit</Badge>
            </div>
          </div>
        </section>

        <div class="mt-6">
          {@render children()}
        </div>
      </main>
    </div>
  </div>
</div>
