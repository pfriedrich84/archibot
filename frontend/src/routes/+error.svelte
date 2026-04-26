<script lang="ts">
  import { base } from '$app/paths';

  let { error, status } = $props();

  function goHome() {
    window.location.href = `${base}/`;
  }

  function retry() {
    window.location.reload();
  }
</script>

<svelte:head>
  <title>ArchiBot Admin · Fehler</title>
</svelte:head>

<div class="min-h-screen bg-slate-950 px-4 py-10 text-slate-100">
  <div class="mx-auto max-w-3xl rounded-3xl border border-slate-800 bg-slate-900/90 p-8 shadow-2xl shadow-slate-950/40">
    <p class="text-sm font-medium uppercase tracking-[0.2em] text-amber-300">Admin UI Error</p>
    <h1 class="mt-3 text-3xl font-semibold text-white">Die Oberfläche konnte nicht geladen werden.</h1>
    <p class="mt-3 text-sm text-slate-300">
      Status: <span class="font-semibold text-white">{status}</span>
    </p>

    <div class="mt-6 rounded-2xl border border-amber-500/20 bg-amber-500/10 p-4 text-sm text-amber-50/90">
      {#if error?.message}
        {error.message}
      {:else}
        Ein unbekannter Fehler ist beim Laden der Seite aufgetreten.
      {/if}
    </div>

    <div class="mt-6 grid gap-3 sm:grid-cols-2">
      <button
        type="button"
        class="rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm font-medium text-slate-100 transition hover:border-slate-600 hover:bg-slate-900"
        onclick={retry}
      >
        Erneut versuchen
      </button>
      <button
        type="button"
        class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm font-medium text-emerald-100 transition hover:border-emerald-400/40 hover:bg-emerald-500/20"
        onclick={goHome}
      >
        Zum Dashboard
      </button>
    </div>

    <p class="mt-6 text-xs text-slate-500">
      Tipp: Wenn die API gerade nicht erreichbar ist, prüfe zuerst ArchiBot unter <code class="rounded bg-slate-950 px-2 py-1 text-slate-300">/api/v1/*</code>.
    </p>
  </div>
</div>
