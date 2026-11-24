/**
 * Money arrives from the server as integer nanodollars. We format it with integer
 * arithmetic only, so the UI never shows a float-rounded amount.
 */
const NANOS_PER_DOLLAR = 1_000_000_000;

interface FormatOptions {
    /** Fewest decimals to show (default 2). */
    minDecimals?: number;
    /** Most decimals to show (default 9: exact). Fewer rounds half-up, for display only. */
    maxDecimals?: number;
}

export function formatUsd(nanos: number | null | undefined, { minDecimals = 2, maxDecimals = 9 }: FormatOptions = {}): string {
    if (nanos === null || nanos === undefined) {
        return '—';
    }

    const negative = nanos < 0;
    const abs = Math.abs(Math.trunc(nanos));
    const step = 10 ** (9 - maxDecimals);
    const rounded = Math.round(abs / step) * step;

    const dollars = Math.floor(rounded / NANOS_PER_DOLLAR);
    let fraction = String(rounded % NANOS_PER_DOLLAR)
        .padStart(9, '0')
        .slice(0, maxDecimals)
        .replace(/0+$/, '');
    fraction = fraction.padEnd(minDecimals, '0');

    const whole = dollars.toLocaleString('en-US');

    return `${negative ? '−' : ''}$${whole}${fraction ? '.' + fraction : ''}`;
}

/** The exact amount, all nine decimals, for tooltips. */
export function exactUsd(nanos: number | null | undefined): string {
    if (nanos === null || nanos === undefined) {
        return '—';
    }

    const abs = Math.abs(nanos);

    return `${nanos < 0 ? '-' : ''}$${Math.floor(abs / NANOS_PER_DOLLAR)}.${String(abs % NANOS_PER_DOLLAR).padStart(9, '0')}`;
}

export function formatTokens(tokens: number): string {
    return tokens.toLocaleString('en-US');
}
