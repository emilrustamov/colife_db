const cellEdge = 'border-b border-line';

export const rtTableScroll = 'w-full min-w-max border-collapse text-[13px] leading-snug';

export const rtTableFluid = 'min-w-full border-collapse text-[13px] leading-snug';

export const rtThead = 'bg-surface-muted/90';

export const rtTheadSticky = 'sticky top-0 z-10 bg-surface-muted/95 backdrop-blur-sm';

export const rtTh = (align = 'left') => {
    const al = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';

    return `${cellEdge} px-3 py-2.5 ${al} text-[11px] font-semibold uppercase tracking-wide text-ink-muted`;
};

export const rtThDense = `${cellEdge} px-1.5 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-ink-muted`;

export const rtTd = `${cellEdge} px-3 py-2.5 text-ink`;

export const rtTdTruncate = `${cellEdge} px-3 py-2.5 text-ink max-w-[240px] truncate`;

export const rtTdLeading = `${cellEdge} px-3 py-2.5 text-ink`;

export const rtTdStrong = `${cellEdge} px-3 py-2.5 font-medium text-ink`;

export const rtTdMuted = `${cellEdge} px-3 py-2.5 text-ink-muted`;

export const rtTdActions = `${cellEdge} px-3 py-2.5 text-right`;

export const rtEmpty = `${cellEdge} px-3 py-14 text-center text-sm text-ink-muted`;
