/**
 * First we will load all of this project's JavaScript dependencies which
 * includes Vue and other libraries. It is a great starting point when
 * building robust, powerful web applications using Vue and Laravel.
 */

require('./bootstrap')

import AppComponent from '../vue/App'
import MenuComponent from '../vue/Widgets/Menu'
import SupportForm from '../vue/Widgets/SupportForm'
import { loadLangAsync, i18n } from './locale'
import { clearFormValidation, pick, startLoading, stopLoading } from './utils'

const routerState = {
    afterLogin: null,
    isLoggedIn: !!localStorage.getItem('token'),
    isLocked: false
}

let loadingRoute

// Note: This has to be before the app is created
// Note: You cannot use app inside of the function
window.router.beforeEach((to, from, next) => {
    // check if the route requires authentication and user is not logged in
    if (to.meta.requiresAuth && !routerState.isLoggedIn) {
        // remember the original request, to use after login
        routerState.afterLogin = to;

        // redirect to login page
        next({ name: 'login' })
        return
    }

    if (routerState.isLocked && to.meta.requiresAuth && !['login', 'payment-status'].includes(to.name)) {
        // redirect to the payment-status page
        next({ name: 'payment-status' })
        return
    }

    if (to.meta.loading) {
        startLoading()
        loadingRoute = to.name
    }

    next()
})

window.router.afterEach((to, from) => {
    if (to.name && loadingRoute === to.name) {
        stopLoading()
        loadingRoute = null
    }

    // When changing a page remove old:
    // - error page
    // - modal backdrop
    $('#error-page,.modal-backdrop.show').remove()
    $('body').css('padding', 0) // remove padding added by unclosed modal

    // Close the mobile menu
    if ($('#header-menu .navbar-collapse.show').length) {
        $('#header-menu .navbar-toggler').click();
    }
})

const app = new Vue({
    components: {
        AppComponent,
        MenuComponent,
    },
    i18n,
    router: window.router,
    data() {
        return {
            authInfo: null,
            isUser: !window.isAdmin && !window.isReseller,
            appName: window.config['app.name'],
            appUrl: window.config['app.url'],
            themeDir: '/themes/' + window.config['app.theme']
        }
    },
    methods: {
        clearFormValidation,
        countriesText(list) {
            if (list && list.length) {
                let result = []

                list.forEach(code => {
                    let country = window.config.countries[code]
                    if (country) {
                        result.push(country[1])
                    } else {
                        console.warn(`Unknown country code: ${code}`)
                    }
                })

                return result.join(', ')
            }

            return this.$t('form.norestrictions')
        },
        hasPermission(type) {
            const key = 'enable' + type.charAt(0).toUpperCase() + type.slice(1)
            return !!(this.authInfo && this.authInfo.statusInfo[key])
        },
        hasRoute(name) {
            return this.$router.resolve({ name: name }).resolved.matched.length > 0
        },
        hasSKU(name) {
            return this.authInfo.statusInfo.skus && this.authInfo.statusInfo.skus.indexOf(name) != -1
        },
        isDegraded() {
            return this.authInfo && this.authInfo.isAccountDegraded
        },
        // Set user state to "logged in"
        loginUser(response, dashboard, update) {
            if (!update) {
                routerState.isLoggedIn = true
                this.authInfo = null
            }

            localStorage.setItem('token', response.access_token)
            localStorage.setItem('refreshToken', response.refresh_token)

            if (response.user) {
                this.authInfo = response.user
            }

            routerState.isLocked = this.isUser && this.authInfo && this.authInfo.isLocked

            if (dashboard !== false) {
                this.$router.push(routerState.afterLogin || { name: response.redirect || 'dashboard' })
            } else if (routerState.isLocked && this.$route.meta.requiresAuth && this.$route.name != 'payment-status') {
                // Always redirect locked user, here we can be after router's beforeEach handler
                this.$router.push({ name: 'payment-status' })
            }

            routerState.afterLogin = null

            if (response.message) {
                this.$toast.success(response.message)
            }

            // Refresh the token before it expires
            let timeout = response.expires_in || 0

            // We'll refresh 60 seconds before the token expires
            if (timeout > 60) {
                timeout -= 60
            }

            // TODO: We probably should try a few times in case of an error
            // TODO: We probably should prevent axios from doing any requests
            //       while the token is being refreshed

            this.refreshTimeout = setTimeout(() => {
                const refresh_token = localStorage.getItem('refreshToken')
                axios.post('api/auth/refresh', { refresh_token }, { headers: { Authorization: null } })
                    .then(response => {
                        this.loginUser(response.data, false, true)
                    })
            }, timeout * 1000)
        },
        // Set user state to "not logged in"
        logoutUser(redirect) {
            routerState.isLoggedIn = false
            this.authInfo = null
            localStorage.removeItem('token')
            localStorage.removeItem('refreshToken')

            if (redirect !== false) {
                this.$router.push({ name: 'login' })
            }

            clearTimeout(this.refreshTimeout)
        },
        logo(mode) {
            let src = this.appUrl + this.themeDir + '/images/logo_' + (mode || 'header') + '.png'

            return `<img src="${src}" alt="${this.appName}">`
        },
        pick,
        startLoading,
        stopLoading,
        errorPage(code, msg, hint) {
            // Until https://github.com/vuejs/vue-router/issues/977 is implemented
            // we can't really use router to display error page as it has two side
            // effects: it changes the URL and adds the error page to browser history.
            // For now we'll be replacing current view with error page "manually".

            if (!msg) msg = this.$te('error.' + code) ? this.$t('error.' + code) : this.$t('error.unknown')
            if (!hint) hint = ''

            const error_page = '<div id="error-page" class="error-page">'
                + `<div class="code">${code}</div><div class="message">${msg}</div><div class="hint">${hint}</div>`
                + '</div>'

            $('#error-page').remove()
            $('#app').append(error_page)

            app.updateBodyClass('error')
        },
        errorHandler(error) {
            stopLoading()

            const status = error.response ? error.response.status : 500
            const message = error.response ? error.response.statusText : ''

            if (status == 401) {
                // Remember requested route to come back to it after log in
                if (this.$route.meta.requiresAuth) {
                    routerState.afterLogin = this.$route
                    this.logoutUser()
                } else {
                    this.logoutUser(false)
                }
            } else {
                if (!error.response) {
                    console.error(error)
                }

                this.errorPage(status, message)
            }
        },
        price(price, currency) {
            if (!currency) {
                currency = 'CHF'
            } else {
                currency = currency.toUpperCase()
            }

            let args = { style: 'currency', currency }

            if (currency == 'BTC') {
                args.minimumFractionDigits = 6
                args.maximumFractionDigits = 9
            }

            // TODO: Set locale argument according to the currently used locale
            return ((price || 0) / 100).toLocaleString('de-DE', args)
        },
        priceLabel(cost, discount, currency) {
            let index = ''

            if (discount) {
                cost = Math.floor(cost * ((100 - discount) / 100))
                index = '\u00B9'
            }

            return this.price(cost, currency) + '/' + this.$t('wallet.month') + index
        },
        clickRecord(event) {
            if (!/^(a|button|svg|path)$/i.test(event.target.nodeName)) {
                $(event.target).closest('tr').find('a').trigger('click')
            }
        },
        pageName(path) {
            let page = this.$route.path

            // check if it is a "menu page", find the page name
            // otherwise we'll use the real path as page name
            window.config.menu.every(item => {
                if (item.location == page && item.page) {
                    page = item.page
                    return false
                }
            })

            page = page.replace(/^\//, '')

            return page ? page : '404'
        },
        supportDialog(container) {
            let dialog = $('#support-dialog')[0]

            if (!dialog) {
                // FIXME: Find a nicer way of doing this
                SupportForm.i18n = i18n
                let form = new Vue(SupportForm)
                form.$mount($('<div>').appendTo(container)[0])
                form.$root = this
                form.$toast = this.$toast
                dialog = form.$el
            }

            dialog.__vue__.show()
        },
        statusClass(obj) {
            if (obj.isDeleted) {
                return 'text-muted'
            }

            if (obj.isDegraded || obj.isAccountDegraded || obj.isSuspended) {
                return 'text-warning'
            }

            if (!obj.isReady) {
                return 'text-danger'
            }

            return 'text-success'
        },
        statusText(obj) {
            if (obj.isDeleted) {
                return this.$t('status.deleted')
            }

            if (obj.isSuspended) {
                return this.$t('status.suspended')
            }

            if (obj.isDegraded || obj.isAccountDegraded) {
                return this.$t('status.degraded')
            }

            if (!obj.isReady) {
                return this.$t('status.notready')
            }

            return this.$t('status.active')
        },
        unlock() {
            routerState.isLocked = this.authInfo.isLocked = false
            this.$router.push({ name: 'dashboard' })
        },
        // Append some wallet properties to the object
        userWalletProps(object) {
            let wallet = this.authInfo.accounts[0]

            if (!wallet) {
                wallet = this.authInfo.wallets[0]
            }

            if (wallet) {
                object.currency = wallet.currency
                if (wallet.discount) {
                    object.discount = wallet.discount
                    object.discount_description = wallet.discount_description
                }
            }
        },
        updateBodyClass(name) {
            // Add 'class' attribute to the body, different for each page
            // so, we can apply page-specific styles
            document.body.className = 'page-' + (name || this.pageName()).replace(/\/.*$/, '').toLowerCase()
        },
        updateStatusInfo() {
            axios.get('/api/v4/users/' + this.authInfo.id + '/status')
                .then(response => {
                    this.authInfo.statusInfo = response.data
                })
        }
    }
})

// Fetch the locale file and the start the app
loadLangAsync().then(() => app.$mount('#app'))

// Add a axios request interceptor
axios.interceptors.request.use(
    config => {
        // Set the Authorization header. Note that some request might force
        // empty Authorization header therefore we check if the header is already set,
        // not whether it's empty
        const token = localStorage.getItem('token')
        if (token && !('Authorization' in config.headers)) {
            config.headers.Authorization = 'Bearer ' + token
        }

        let loader = config.loader
        if (loader) {
            startLoading(loader)
        }

        return config
    },
    error => {
        // Do something with request error
        return Promise.reject(error)
    }
)

// Add a axios response interceptor for general/validation error handler
axios.interceptors.response.use(
    response => {
        if (response.config.onFinish) {
            response.config.onFinish()
        }

        let loader = response.config.loader
        if (loader) {
            stopLoading(loader)
        }

        return response
    },
    error => {
        if (error.config && error.config.loader) {
            stopLoading(error.config.loader)
        }

        // Do not display the error in a toast message, pass the error as-is
        if (axios.isCancel(error) || (error.config && error.config.ignoreErrors)) {
            return Promise.reject(error)
        }

        if (error.config && error.config.onFinish) {
            error.config.onFinish()
        }

        let error_msg

        const status = error.response ? error.response.status : 200
        const data = error.response ? error.response.data : {}

        if (status == 422 && data.errors) {
            error_msg = app.$t('error.form')

            const modal = $('div.modal.show')

            $(modal.length ? modal : 'form').each((i, form) => {
                form = $(form)

                $.each(data.errors, (idx, msg) => {
                    const input_name = (form.data('validation-prefix') || form.find('form').first().data('validation-prefix') || '') + idx
                    let input = form.find('#' + input_name)

                    if (!input.length) {
                        input = form.find('[name="' + input_name + '"]');
                    }

                    if (input.length) {
                        // Create an error message
                        // API responses can use a string, array or object
                        let msg_text = ''
                        if (typeof(msg) !== 'string') {
                            $.each(msg, (index, str) => {
                                msg_text += str + ' '
                            })
                        }
                        else {
                            msg_text = msg
                        }

                        let feedback = $('<div class="invalid-feedback">').text(msg_text)

                        if (input.is('.list-input')) {
                            // List input widget
                            let controls = input.children(':not(:first-child)')

                            if (!controls.length && typeof msg == 'string') {
                                // this is an empty list (the main input only)
                                // and the error message is not an array
                                input.find('.main-input').addClass('is-invalid')
                            } else {
                                controls.each((index, element) => {
                                    if (msg[index]) {
                                        $(element).find('input').addClass('is-invalid')
                                    }
                                })
                            }

                            input.addClass('is-invalid').next('.invalid-feedback').remove()
                            input.after(feedback)
                        } else {
                            // a special case, e.g. the invitation policy widget
                            if (input.is('select') && input.parent().is('.input-group-select.selected')) {
                                input = input.next()
                            }

                            // Standard form element
                            input.addClass('is-invalid')
                            input.parent().find('.invalid-feedback').remove()
                            input.parent().append(feedback)
                        }
                    }
                })

                form.find('.is-invalid:not(.list-input)').first().focus()
            })
        }
        else if (data.status == 'error') {
            error_msg = data.message
        }
        else {
            error_msg = error.request ? error.request.statusText : error.message
        }

        app.$toast.error(error_msg || app.$t('error.server'))

        // Pass the error as-is
        return Promise.reject(error)
    }
)
