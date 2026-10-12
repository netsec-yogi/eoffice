/** Indian-grouped rupees; null shows as a dash. */
export const inr = (n: number | string | null | undefined) =>
    n === null || n === undefined || n === '' ? '—' : `₹ ${Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
