import { formatDateTime, isIsoDateTime } from '@/lib/datetime';

const SECRET_KEY_PATTERN =
    /(password|secret|token|key|credential|authorization|cookie)/i;

export type DisplayEntry = {
    key: string;
    label: string;
    value: string;
};

export function humanLabel(key: string): string {
    return key
        .replace(/_id$/i, '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function formatRecord(value: Record<string, unknown>): string {
    const entries = Object.entries(value)
        .filter(
            ([, nestedValue]) =>
                nestedValue !== null &&
                nestedValue !== undefined &&
                nestedValue !== '',
        )
        .map(
            ([nestedKey, nestedValue]) =>
                `${humanLabel(nestedKey)}: ${formatDisplayValue(nestedValue, nestedKey)}`,
        );

    return entries.length > 0 ? entries.join(' · ') : '—';
}

export function formatDisplayValue(value: unknown, key = ''): string {
    if (SECRET_KEY_PATTERN.test(key)) {
        return 'Redacted';
    }

    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (isIsoDateTime(value)) {
        return formatDateTime(value);
    }

    if (Array.isArray(value)) {
        if (value.length === 0) {
            return '—';
        }

        return value
            .map((item) =>
                isRecord(item)
                    ? formatRecord(item)
                    : formatDisplayValue(item, key),
            )
            .join(', ');
    }

    if (isRecord(value)) {
        return formatRecord(value);
    }

    return String(value);
}

export function displayEntries(
    record: Record<string, unknown> | null | undefined,
): DisplayEntry[] {
    if (!record) {
        return [];
    }

    return Object.entries(record).map(([key, value]) => ({
        key,
        label: humanLabel(key),
        value: formatDisplayValue(value, key),
    }));
}

export function formatTarget(
    type: string | null,
    id: string | number | null,
): string {
    if (!type && !id) {
        return '—';
    }

    const label = type
        ? humanLabel(type.replace(/\\/g, ' ').split(' ').pop() ?? type)
        : 'Record';

    return id ? `${label} reference ${id}` : label;
}
