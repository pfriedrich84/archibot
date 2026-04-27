<script lang="ts">
  import { Sidebar, SidebarBrand, SidebarDropdownItem, SidebarDropdownWrapper, Navbar, NavBrand, Badge } from 'flowbite-svelte';
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
    <aside class="hidden w-72 border-r border-slate-800/80 bg-slate-950/75 xl:block">
      <Sidebar class="h-full border-none bg-transparent px-4 py-5">
        <SidebarBrand href={navHref('/')} class="px-2">
          <img src={`${base}/logo.png`} class="mr-3 h-10 w-10 rounded-xl bg-slate-800 p-1" alt="ArchiBot" />
          <div>
            <p class="text-lg font-semibold text-white">ArchiBot</p>
            <p class="text-xs text-slate-500">Admin-Konsole</p>
          </div>
        </SidebarBrand>

        <nav class="mt-8 space-y-1.5">
          {#each navItems as item}
            <a
              href={navHref(item.href)}
              data-sveltekit-preload-data="hover"
              aria-current={isActive(item.href) ? 'page' : undefined}
              class={`flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition-colors ${
                isActive(item.href)
                  ? 'bg-slate-800/90 text-white shadow-inner shadow-slate-950/40 ring-1 ring-inset ring-emerald-500/15'
                  : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'
              }`}
            >
              <span class="text-base leading-none">{item.emoji}</span>
              <span class="truncate text-slate-100">{item.label}</span>
            </a>
          {/each}
        </nav>

        <div class="mt-8 px-2">
          <p class="text-[11px] font-medium uppercase tracking-[0.22em] text-slate-600">Arbeitsbereich</p>
        </div>

        <SidebarDropdownWrapper class="mt-6 rounded-2xl border border-slate-800/80 bg-slate-900/50 p-4">
          <div class="mb-2 flex items-center justify-between">
            <span class="text-sm font-medium text-slate-300">Status</span>
            <Badge color="gray" class="border border-slate-700 bg-slate-950/60 text-slate-300">Live</Badge>
          </div>
          <SidebarDropdownItem class="text-sm text-slate-500">
            Review, Freigaben und Jobs laufen nativ unter `/app`.
          </SidebarDropdownItem>
          <SidebarDropdownItem class="text-sm text-slate-500">
            API-Endpunkte kommen aus `/api/v1/*`.
          </SidebarDropdownItem>
        </SidebarDropdownWrapper>

        <div class="mt-4 rounded-2xl border border-slate-800/80 bg-slate-900/45 p-4 text-slate-300">
          <p class="text-sm font-semibold text-slate-200">Hinweis</p>
          <p class="mt-1 text-sm text-slate-500">Die Oberfläche priorisiert tägliche Review- und Betriebsaufgaben statt Setup- oder Entwicklerdetails.</p>
        </div>
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
            class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-2 text-sm font-medium text-slate-200"
            onclick={toggleLocale}
          >
            {locale.toUpperCase()}
          </button>
        </div>
      </Navbar>

      <div class="border-b border-slate-800 bg-slate-950/70 xl:hidden">
        <div class="overflow-x-auto px-4 py-3">
          <nav class="flex min-w-max items-center gap-2">
            {#each navItems as item}
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
                <span>{item.emoji}</span>
                <span>{item.label}</span>
              </a>
            {/each}
          </nav>
        </div>
      </div>

      <main class="flex-1 px-4 py-4 md:px-8 xl:px-10">
        <section class="surface-gradient rounded-3xl border border-slate-800/80 px-6 py-5 shadow-2xl shadow-slate-950/20">
          <div class="flex items-start justify-between gap-4">
            <div>
              <p class="text-xs font-medium uppercase tracking-[0.24em] text-emerald-300">Admin Workspace</p>
              <h1 class="mt-2 text-3xl font-semibold text-white">{title}</h1>
              {#if subtitle}
                <p class="mt-2 max-w-4xl text-sm text-slate-300">{subtitle}</p>
              {/if}
            </div>
            <Badge color="gray" class="hidden rounded-full border border-slate-700 bg-slate-950/60 px-3 py-1.5 text-slate-300 lg:inline-flex">
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
