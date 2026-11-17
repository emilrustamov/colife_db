/**
 * When a permission is selected, also select these companions.
 * Keep in sync with App\Support\AdminPermissionPolicy::companions().
 *
 * @type {Record<string, string[]>}
 */
export const PERMISSION_COMPANIONS = {
    'users.manage': ['directory.users'],
    'users.edit': ['directory.users'],
    'users.delete': ['directory.users'],
    'roles.manage': ['directory.roles'],
    'ol-bot.templates.edit': ['ol-bot.templates'],
};

/**
 * @param {Array<{ id: number|string, name: string }>} permissionOptions
 * @param {Array<number|string>} selectedIds
 * @returns {Array<number|string>}
 */
export const expandPermissionIds = (permissionOptions, selectedIds) => {
    const byName = new Map(permissionOptions.map((opt) => [opt.name, opt.id]));
    const byId = new Map(permissionOptions.map((opt) => [opt.id, opt.name]));
    const set = new Set(selectedIds);

    for (const id of [...set]) {
        const name = byId.get(id);
        if (!name) {
            continue;
        }

        for (const companion of PERMISSION_COMPANIONS[name] ?? []) {
            const companionId = byName.get(companion);
            if (companionId !== undefined) {
                set.add(companionId);
            }
        }
    }

    return [...set];
};
