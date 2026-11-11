export const MENU_GROUPS = [
    {
        id: 'access',
        labelKey: 'menuGroupAccess',
        tone: 'violet',
        keys: ['users', 'roles'],
    },
    {
        id: 'people',
        labelKey: 'menuGroupPeople',
        tone: 'sky',
        keys: ['contacts', 'contact-types', 'contact-phones', 'contact-emails'],
    },
    {
        id: 'crm',
        labelKey: 'menuGroupCrm',
        tone: 'emerald',
        keys: ['pipelines', 'stages'],
    },
    {
        id: 'realty',
        labelKey: 'menuGroupRealty',
        tone: 'amber',
        keys: ['buildings', 'apartments', 'apartment-types', 'units', 'metro-stations'],
    },
    {
        id: 'contracts',
        labelKey: 'menuGroupContracts',
        tone: 'rose',
        keys: ['unit-stays', 'apartment-ownerships'],
    },
    {
        id: 'ops',
        labelKey: 'menuGroupOps',
        tone: 'cyan',
        keys: ['disk', 'utilities', 'bitrix-units-snapshot'],
    },
    {
        id: 'olBot',
        labelKey: 'menuGroupOlBot',
        tone: 'violet',
        keys: ['ol-bot-analytics', 'ol-bot-templates'],
    },
];

export const MENU_TONE_CLASSES = {
    violet: {
        icon: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
        active: 'bg-violet-50 text-violet-800 ring-1 ring-violet-200/80 dark:bg-violet-500/15 dark:text-violet-200 dark:ring-violet-400/25',
        dot: 'bg-violet-500 dark:bg-violet-400',
    },
    sky: {
        icon: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
        active: 'bg-sky-50 text-sky-800 ring-1 ring-sky-200/80 dark:bg-sky-500/15 dark:text-sky-200 dark:ring-sky-400/25',
        dot: 'bg-sky-500 dark:bg-sky-400',
    },
    emerald: {
        icon: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
        active: 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200/80 dark:bg-emerald-500/15 dark:text-emerald-200 dark:ring-emerald-400/25',
        dot: 'bg-emerald-500 dark:bg-emerald-400',
    },
    amber: {
        icon: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
        active: 'bg-amber-50 text-amber-900 ring-1 ring-amber-200/80 dark:bg-amber-500/15 dark:text-amber-200 dark:ring-amber-400/25',
        dot: 'bg-amber-500 dark:bg-amber-400',
    },
    rose: {
        icon: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
        active: 'bg-rose-50 text-rose-800 ring-1 ring-rose-200/80 dark:bg-rose-500/15 dark:text-rose-200 dark:ring-rose-400/25',
        dot: 'bg-rose-500 dark:bg-rose-400',
    },
    cyan: {
        icon: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300',
        active: 'bg-cyan-50 text-cyan-800 ring-1 ring-cyan-200/80 dark:bg-cyan-500/15 dark:text-cyan-200 dark:ring-cyan-400/25',
        dot: 'bg-cyan-500 dark:bg-cyan-400',
    },
};

/**
 * @param {Array<{ key: string }>} items
 * @param {string[]} orderedKeys
 * @returns {Array<{ key: string }>}
 */
export const orderMenuItems = (items, orderedKeys) => {
    const byKey = new Map(items.map((item) => [item.key, item]));
    const ordered = [];

    for (const key of orderedKeys) {
        const item = byKey.get(key);
        if (item) {
            ordered.push(item);
            byKey.delete(key);
        }
    }

    for (const item of byKey.values()) {
        ordered.push(item);
    }

    return ordered;
};
