const cellEdge = 'border-b border-slate-200 dark:border-slate-700';

export const rtTableScroll = 'w-full min-w-max border-collapse text-sm';

export const rtTableFluid = 'min-w-full border-collapse text-sm';

export const rtThead = 'bg-slate-50/90 dark:bg-slate-800/90';

export const rtTheadSticky = 'sticky top-0 z-10 bg-slate-50/95 backdrop-blur-sm dark:bg-slate-800/95';

export const rtTh = (align = 'left') => {
    const al = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';

    return `${cellEdge} px-2.5 py-2 ${al} text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400`;
};

export const rtThDense = `${cellEdge} px-1 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400`;

export const rtTd = `${cellEdge} px-2.5 py-1.5 text-slate-700 dark:text-slate-200`;

export const rtTdTruncate = `${cellEdge} px-2.5 py-1.5 text-slate-700 dark:text-slate-200 max-w-[220px] truncate`;

export const rtTdLeading = `${cellEdge} px-2.5 py-1.5 text-slate-800 dark:text-slate-200`;

export const rtTdStrong = `${cellEdge} px-2.5 py-1.5 font-medium text-slate-800 dark:text-slate-200`;

export const rtTdMuted = `${cellEdge} px-2.5 py-1.5 text-slate-700 dark:text-slate-300`;

export const rtTdActions = `${cellEdge} px-2.5 py-1.5 text-right`;

export const rtEmpty = `${cellEdge} px-2.5 py-10 text-center text-sm text-slate-500 dark:text-slate-400`;
