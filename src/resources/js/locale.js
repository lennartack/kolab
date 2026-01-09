import Vue from 'vue'
import VueI18n from 'vue-i18n'

// We do pre-load English localization as this is possible
// the only one that is complete and used as a fallback.
import messages from '../build/js/en.json'

Vue.use(VueI18n)

export const i18n = new VueI18n({
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: messages },
    silentFallbackWarn: true
})

let currentLanguage

const loadedLanguages = ['en'] // our default language that is preloaded
const loadedThemeLanguages = []

const setI18nLanguage = (lang) => {
    i18n.locale = lang

    document.querySelector('html').setAttribute('lang', lang)

    // Set language for API requests
    // Note, it's kinda redundant as we support the cookie
    window.axios.defaults.headers.common['Accept-Language'] = lang

    // Save the selected language in a cookie, so it can be used server-side
    // after page reload. Make the cookie valid for 10 years
    const age = 10 * 60 * 60 * 24 * 365
    document.cookie = 'language=' + lang + '; max-age=' + age + '; path=/; secure'

    // Load additional localization from the theme
    return loadThemeLang(lang)
}

const loadThemeLang = (lang) => {
    if (loadedThemeLanguages.includes(lang)) {
        return
    }

    const theme = window.config['app.theme']

    if (theme && theme != 'default') {
        return import(/* webpackChunkName: "locale/[request]" */ `../build/js/${theme}-${lang}.json`)
            .then(messages => {
                i18n.mergeLocaleMessage(lang, messages.default)
                loadedThemeLanguages.push(lang)
            })
            .catch(error => { /* ignore errors */ })
    }
}

export const getLang = () => {
    if (!currentLanguage) {
        currentLanguage = document.querySelector('html').getAttribute('lang') || 'en'
    }

    return currentLanguage
}

export const setLang = lang => {
    currentLanguage = lang
    loadLangAsync()
}

export function loadLangAsync() {
    const lang = getLang()

    // If the language was already loaded
    if (loadedLanguages.includes(lang)) {
        return Promise.resolve(setI18nLanguage(lang))
    }

    // If the language hasn't been loaded yet
    return import(/* webpackChunkName: "locale/[request]" */ `../build/js/${lang}.json`)
        .then(messages => {
            i18n.setLocaleMessage(lang, messages.default)
            loadedLanguages.push(lang)
            return Promise.resolve(setI18nLanguage(lang))
        })
        .catch(error => { /* ignore errors */ })
}
