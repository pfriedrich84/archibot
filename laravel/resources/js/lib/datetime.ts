import { page } from '@inertiajs/svelte';

type DisplayPreferences = {
    timezone?: string | null;
    date_format?: string | null;
};

const ISO_DATE_TIME_PATTERN =
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/;
const DEFAULT_FORMAT = 'dd.mm.yyyy hh:mm:ss';

function preferences(): DisplayPreferences {
    const props = page.props as Record<string, unknown>;
    const display = props.display;

    return typeof display === 'object' && display !== null ? display : {};
}

function userTimezone(): string {
    return (
        preferences().timezone ||
        Intl.DateTimeFormat().resolvedOptions().timeZone ||
        'UTC'
    );
}

function userFormat(): string {
    return preferences().date_format || DEFAULT_FORMAT;
}

function partsFor(date: Date, timezone: string): Record<string, string> {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).formatToParts(date);

    return Object.fromEntries(
        parts
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );
}

export function isIsoDateTime(value: unknown): value is string {
    return typeof value === 'string' && ISO_DATE_TIME_PATTERN.test(value);
}

export function formatDateTime(
    value: string | null | undefined,
    fallback = '—',
): string {
    if (!value) {
        return fallback;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    const timezone = userTimezone();
    const format = userFormat();
    const parts = partsFor(date, timezone);

    return format.replace(
        /yyyy|YYYY|dd|DD|mm|MM|hh|HH|ss|SS/g,
        (token, offset) => {
            if (token === 'yyyy' || token === 'YYYY') {
                return parts.year;
            }

            if (token === 'dd' || token === 'DD') {
                return parts.day;
            }

            if (token === 'hh' || token === 'HH') {
                return parts.hour;
            }

            if (token === 'ss' || token === 'SS') {
                return parts.second;
            }

            if (token === 'MM') {
                return parts.minute;
            }

            const previous = format[offset - 1];
            const next = format[offset + token.length];

            return previous === ':' || next === ':'
                ? parts.minute
                : parts.month;
        },
    );
}
