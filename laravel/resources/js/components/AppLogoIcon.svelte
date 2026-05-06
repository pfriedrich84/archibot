<script lang="ts">
    let {
        class: className = '',
        alt = '',
        ...rest
    }: {
        class?: string;
        alt?: string;
        [key: string]: unknown;
    } = $props();
</script>

<img src="/archibot-logo.png" {alt} class={className} {...rest} />
