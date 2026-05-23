export type PaperlessEntityOption = { id: number; name: string };

export function numericId(value: unknown): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const id = Number(value);

    return Number.isInteger(id) && id > 0 ? id : null;
}

export function paperlessLabel(
    idValue: unknown,
    nameValue?: unknown,
    options: PaperlessEntityOption[] = [],
): string {
    const id = numericId(idValue);
    const explicitName = typeof nameValue === 'string' ? nameValue.trim() : '';
    const optionName = id
        ? options.find((option) => option.id === id)?.name
        : '';
    const label = explicitName || optionName || '';

    if (label && id) {
        return `${label} (#${id})`;
    }

    if (label) {
        return label;
    }

    if (id) {
        return `Unknown (#${id})`;
    }

    return '—';
}
