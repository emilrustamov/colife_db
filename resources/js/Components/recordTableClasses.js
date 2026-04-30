const cellBorder = 'border border-slate-200 dark:border-slate-600';

export const rtTableScroll = 'w-full min-w-max border-collapse text-sm';

export const rtTableFluid = 'min-w-full border-collapse text-sm';

export const rtThead = 'bg-slate-50 dark:bg-slate-800';

export const rtTheadSticky = 'sticky top-0 z-10 bg-slate-50 dark:bg-slate-800';

export const rtTh = (align = 'left') => {
    const al = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';

    return `${cellBorder} px-3 py-2.5 ${al} text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-300`;
};

export const rtThDense = `${cellBorder} px-1 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-300`;

export const rtTd = `${cellBorder} px-3 py-2.5 text-slate-700 dark:text-slate-200`;

export const rtTdTruncate = `${cellBorder} px-3 py-2.5 text-slate-700 dark:text-slate-200 max-w-[220px] truncate`;

export const rtTdLeading = `${cellBorder} px-3 py-2.5 text-slate-800 dark:text-slate-200`;

export const rtTdStrong = `${cellBorder} px-3 py-2.5 font-medium text-slate-800 dark:text-slate-200`;

export const rtTdMuted = `${cellBorder} px-3 py-2.5 text-slate-700 dark:text-slate-300`;

export const rtTdActions = `${cellBorder} px-3 py-2.5 text-right`;

export const rtEmpty = `${cellBorder} px-3 py-8 text-center text-sm text-slate-500 dark:text-slate-400`;
