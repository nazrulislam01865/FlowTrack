export const money = (value: number | string | null | undefined, currency = 'USD') =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(Number(value || 0));

export const formatDate = (value?: string | null) => {
    if (!value) return '—';
    return new Date(`${value.slice(0, 10)}T00:00:00`).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

export const initials = (name?: string | null) =>
    (name || '?')
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

export const dueClass = (value?: string | null) => {
    if (!value) return '';
    const date = value.slice(0, 10);
    const today = new Date().toISOString().slice(0, 10);
    return date < today ? 'overdue' : date === today ? 'today' : '';
};

export const records = (options: any, type: string): any[] => options?.master?.[type] || [];
