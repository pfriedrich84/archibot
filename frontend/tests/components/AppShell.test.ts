import { render } from 'svelte/server';
import { describe, expect, it } from 'vitest';

import PagePlaceholder from '../../src/lib/components/PagePlaceholder.svelte';

describe('PagePlaceholder', () => {
  it('renders migration guidance and planned API path', () => {
    const { body } = render(PagePlaceholder, {
      props: {
        title: 'Review Queue',
        description: 'Batch-Aktionen folgen in der Svelte-Migration.',
        apiPath: '/api/v1/review/*'
      }
    });

    expect(body).toContain('Migration Workbench');
    expect(body).toContain('Review Queue');
    expect(body).toContain('/api/v1/review/*');
  });
});
