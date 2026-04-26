import { render } from 'svelte/server';
import { describe, expect, it } from 'vitest';

import StatCard from '../../src/lib/components/StatCard.svelte';

describe('StatCard', () => {
  it('renders KPI title, value and hint', () => {
    const { body } = render(StatCard, {
      props: {
        title: 'Pending Review',
        value: 12,
        hint: 'Vorschläge warten auf Freigabe',
        trend: '+3 heute',
        accent: 'emerald'
      }
    });

    expect(body).toContain('Pending Review');
    expect(body).toContain('12');
    expect(body).toContain('Vorschläge warten auf Freigabe');
    expect(body).toContain('+3 heute');
  });
});
