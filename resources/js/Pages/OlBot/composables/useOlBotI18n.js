import { computed } from 'vue';
import { useAppPreferences } from '../../../composables/useAppPreferences';
import { formatDateTime as formatDateTimeValue } from '../../../utils/datetime';
import { olBotMessages } from '../i18n/olBotMessages';

/**
 * @returns {{
 *   locale: import('vue').Ref<string>,
 *   t: (key: string) => string,
 *   formatDateTime: (value: unknown) => string,
 *   scenarioLabel: (name: string) => string,
 *   skillLabel: (name: string) => string,
 *   toolLabel: (name: string) => string,
 * }}
 */
export const useOlBotI18n = () => {
    const { locale, t: translate } = useAppPreferences();

    /**
     * @param {string} key
     * @returns {string}
     */
    const t = (key) => translate(olBotMessages, key);

    /**
     * @param {unknown} value
     * @returns {string}
     */
    const formatDateTime = (value) => formatDateTimeValue(value, locale.value);

    /**
     * @param {string} name
     * @returns {string}
     */
    const scenarioLabel = (name) => t(`scenario_${name}`);

    /**
     * @param {string} name
     * @returns {string}
     */
    const skillLabel = (name) => t(`skill_${name}`);

    /**
     * @param {string} name
     * @returns {string}
     */
    const toolLabel = (name) => t(`tool_${name}`);

    const messages = computed(() => olBotMessages[locale.value] ?? olBotMessages.ru);

    return {
        locale,
        messages,
        t,
        formatDateTime,
        scenarioLabel,
        skillLabel,
        toolLabel,
    };
};
