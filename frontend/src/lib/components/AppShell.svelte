<script lang="ts">
  import { Sidebar, SidebarBrand, SidebarDropdownItem, SidebarDropdownWrapper, Navbar, NavBrand, Badge } from 'flowbite-svelte';
  import { base } from '$app/paths';
  import { page } from '$app/state';
  import { navGroups } from '$lib/nav';

  let { title = 'Dashboard', subtitle = '', navBadges = {}, children } = $props<{
    title?: string;
    subtitle?: string;
    navBadges?: Record<string, number | string | undefined>;
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

  function badgeFor(key: string | undefined) {
    if (!key) return undefined;
    const value = navBadges[key];
    if (value === undefined || value === null || value === '' || value === 0) return undefined;
    return value;
  }
</script>

<div class="min-h-screen bg-slate-950 text-slate-100">
  <div class="pointer-events-none fixed inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.13),transparent_32%),radial-gradient(circle_at_top_right,rgba(56,189,248,0.09),transparent_28%)]"></div>
  <div class="flex min-h-screen">
    <aside class="hidden w-72 border-r border-slate-800/80 bg-slate-950/80 backdrop-blur xl:block">
      <Sidebar class="h-full border-none bg-transparent px-4 py-5">
        <SidebarBrand href={navHref('/')} class="px-2">
          <img src={`${base}/logo.png`} class="mr-3 h-10 w-10 rounded-xl bg-slate-800 p-1" alt="ArchiBot" />
          <div>
            <p class="text-lg font-semibold text-white">ArchiBot</p>
            <p class="text-xs text-slate-500">Review Cockpit</p>
          </div>
        </SidebarBrand>

        <nav class="mt-8 space-y-7" aria-label="Hauptnavigation">
          {#each navGroups as group}
            <div>
              <p class="px-3 text-[11px] font-medium uppercase tracking-[0.22em] text-slate-600">{group.label}</p>
              <div class="mt-2 space-y-1.5">
                {#each group.items as item}
                  {@const badge = badgeFor(item.badgeKey)}
                  <a
                    href={navHref(item.href)}
                    data-sveltekit-preload-data="hover"
                    aria-current={isActive(item.href) ? 'page' : undefined}
                    class={`flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition-all ${
                      isActive(item.href)
                        ? 'bg-slate-800/95 text-white shadow-inner shadow-slate-950/40 ring-1 ring-inset ring-emerald-500/20'
                        : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'
                    }`}
                  >
                    <span class="text-base leading-none" aria-hidden="true">{item.emoji}</span>
                    <span class="min-w-0 flex-1 truncate text-slate-100">{item.label}</span>
                    {#if badge !== undefined}
                      <span class="rounded-full border border-emerald-500/30 bg-emerald-500/12 px-2 py-0.5 text-xs text-emerald-100">{badge}</span>
                    {/if}
                  </a>
                {/each}
              </div>
            </div>
          {/each}
        </nav>

        <SidebarDropdownWrapper class="mt-8 rounded-2xl border border-slate-800/80 bg-slate-900/50 p-4">
          <div class="mb-2 flex items-center justify-between">
            <span class="text-sm font-medium text-slate-300">UX-Modus</span>
            <Badge color="green" class="border border-emerald-500/30 bg-emerald-500/10 text-emerald-100">Review-first</Badge>
          </div>
          <SidebarDropdownItem class="text-sm text-slate-500">
            Schnell prüfen, sicher freigeben, Fehler sofort triagieren.
          </SidebarDropdownItem>
        </SidebarDropdownWrapper>
      </Sidebar>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <Navbar class="sticky top-0 z-20 border-b border-slate-800/80 bg-slate-950/90 backdrop-blur">
        <NavBrand href={navHref('/')} class="gap-3 xl:hidden">
          <img src={`${base}/logo.png`} class="h-9 w-9 rounded-xl bg-slate-800 p-1" alt="ArchiBot" />
          <span class="self-center whitespace-nowrap text-xl font-semibold text-white">ArchiBot</span>
        </NavBrand>

        <div class="ms-auto flex items-center gap-3">
          <button
            type="button"
            class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-slate-600 hover:text-white"
            onclick={toggleLocale}
            aria-label="Sprache umschalten"
          >
            {locale.toUpperCase()}
          </button>
        </div>
      </Navbar>

      <div class="border-b border-slate-800 bg-slate-950/70 xl:hidden">
        <div class="overflow-x-auto px-4 py-3">
          <nav class="flex min-w-max items-center gap-2" aria-label="Mobile Navigation">
            {#each navGroups.flatMap((group) => group.items) as item}
              {@const badge = badgeFor(item.badgeKey)}
              <a
                href={navHref(item.href)}
                data-sveltekit-preload-data="hover"
                aria-current={isActive(item.href) ? 'page' : undefined}
                class={`inline-flex items-center gap-2 rounded-full border px-3 py-2 text-sm font-medium transition-colors ${
                  isActive(item.href)
                    ? 'border-emerald-500/40 bg-emerald-500/15 text-emerald-100'
                    : 'border-slate-800 bg-slate-900 text-slate-300 hover:border-slate-700 hover:text-white'
                }`}
              >
                <span aria-hidden="true">{item.emoji}</span>
                <span>{item.label}</span>
                {#if badge !== undefined}<span class="text-xs opacity-80">{badge}</span>{/if}
              </a>
            {/each}
          </nav>
        </div>
      </div>

      <main class="flex-1 px-4 py-4 md:px-8 xl:px-10">
        <section class="surface-gradient rounded-3xl border border-slate-800/80 px-6 py-5 shadow-2xl shadow-slate-950/20">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <p class="text-xs font-medium uppercase tracking-[0.24em] text-emerald-300">Review Workspace</p>
              <h1 class="mt-2 text-3xl font-semibold text-white">{title}</h1>
              {#if subtitle}
                <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-300">{subtitle}</p>
              {/if}
            </div>
            <Badge color="gray" class="w-fit rounded-full border border-slate-700 bg-slate-950/60 px-3 py-1.5 text-slate-300">
              Betriebsansicht aktiv
            </Badge>
          </div>
        </section>

        <div class="mt-6">
          {@render children()}
        </div>
      </main>
    </div>
  </div>
</div>
