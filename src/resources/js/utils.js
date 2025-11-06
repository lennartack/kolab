
/**
 * Clear (bootstrap) form validation state
 */
const clearFormValidation = (form) => {
    $(form).find('.is-invalid').removeClass('is-invalid')
    $(form).find('.invalid-feedback').remove()
}

/**
 * File downloader
 */
const downloadFile = (url, filename) => {
    // TODO: This might not be a best way for big files as the content
    //       will be stored (temporarily) in browser memory
    // TODO: This method does not show the download progress in the browser
    //       but it could be implemented in the UI, axios has 'progress' property
    axios.get(url, { responseType: 'blob' })
        .then(response => {
            const link = document.createElement('a')

            if (!filename) {
                const contentDisposition = response.headers['content-disposition']
                filename = 'unknown'

                if (contentDisposition) {
                    const match = contentDisposition.match(/filename="?(.+)"?/);
                    if (match && match.length === 2) {
                        filename = match[1];
                    }
                }
            }

            link.href = window.URL.createObjectURL(response.data)
            link.download = filename
            link.click()
        })
}

/**
 * Create an object copy with specified properties only
 */
const pick = (obj, properties) => {
    let result = {}

    properties.forEach(prop => {
        if (prop in obj) {
            result[prop] = obj[prop]
        }
    })

    return result
}

const loader = '<div class="app-loader"><div class="spinner-border" role="status"><span class="visually-hidden">Loading</span></div><div class="text"></div></div>'

let isLoading = 0

/**
 * Display the 'loading...' element, lock the UI
 *
 * @param array|string|DOMElement|null|bool|jQuery $element Supported input:
 *              - DOMElement or jQuery collection or selector string: for element-level loader inside
 *              - array: for element-level loader inside the element specified in the first array element
 *              - undefined, null or true: for page-level loader
 * @param object $style Additional element style (and loader text)
 */
const startLoading = (element, style = null) => {
    let small = false

    if (Array.isArray(element)) {
        style = element[1]
        element = element[0]
    }

    if (element && element !== true) {
        // The loader inside some page element
        small = true

        if (style) {
            small = style.small
            delete style.small
            $(element).css(style)
        } else {
            $(element).css('position', 'relative')
        }
    } else {
        // The full page loader
        isLoading++
        let loading = $('#app > .app-loader').removeClass('fadeOut')
        if (loading.length) {
            return
        }

        element = $('#app')
    }

    const loaderElement = $(loader)

    if (small) {
        loaderElement.addClass('small')
    }

    loaderElement.find('.text').text(style && style.text ? style.text : '')

    $(element).append(loaderElement)

    return loaderElement
}

/**
 * Hide the "loading" element
 *
 * @param array|string|DOMElement|null|bool|jQuery $element
 * @see startLoading()
 */
const stopLoading = (element) => {
    if (element && element !== true) {
        if (Array.isArray(element)) {
            element = element[0]
        }

        $(element).find('.app-loader').remove()
    } else if (isLoading > 0) {
        $('#app > .app-loader').addClass('fadeOut')
        isLoading--;
    }
}

let stripe = null

const stripeInit = (callback) => {
    let script = $('#stripe-script')

    if (!script.length) {
        script = document.createElement('script')

        script.id = 'stripe-script'
        script.src = 'https://js.stripe.com/v3/'
        script.onload = () => {
            stripe = Stripe(window.config.stripePK)
            callback()
        }

        document.getElementsByTagName('head')[0].appendChild(script)
    } else {
        stripe = Stripe(window.config.stripePK)
        callback()
    }
}

/**
 * Executes payment checkout.
 *
 * @param object Vue component object
 * @param array  Payment request parameters (Response from the payments API)
 *
 * @return bool Returns false if no supported checkout method is requested, True otherwise
 */
const paymentCheckout = (component, data) => {
    if (data.redirectUrl) {
        location.href = data.redirectUrl
    } else if (data.newWindowUrl) {
        window.open(data.newWindowUrl, '_blank')
    } else if (data.id) {
        stripeInit(() => {
            stripe.redirectToCheckout({ sessionId: data.id }).then(result => {
                // If it fails due to a browser or network error,
                // display the localized error message to the user
                if (result.error) {
                    component.$toast.error(result.error.message)
                }
            })
        })
    } else {
        return false
    }

    return true
}

/**
 * Initializes a user email autocompletion on a form input element.
 *
 * @param DOMElement $input  Input element
 * @param array      $params Search parameters (alias, limit)
 */
const userAutocomplete = (input, params = {}) => {
    if (!window.config['app.with_user_search']) {
        return
    }

    const listId = input.id + '-datalist'

    // Note: <datalist> element is a simple solution, it does not allow
    // styling nor structured content though. It also displays differently
    // in different browsers.

    let controller = null
    let timeout = null
    let datalist = $('<datalist>').attr('id', listId)

    const search_request = search => {
        controller = new AbortController()

        params = Object.assign({ alias: 0, limit: 10 }, params, { search })

        axios.get('api/v4/search/user', { params, signal: controller.signal })
            .then(response => {
                datalist.empty()
                response.data.list.forEach(user => {
                    let label = user.name ? `${user.name} <${user.email}>` : user.email
                    datalist.append($('<option>').val(user.email).text(label))
                })
            })
            .catch(() => {
                // ignore error
            })
            .finally(() => {
                controller = null
            })
    }

    $(input).attr({ autocomplete: 'off', list: listId })
        .after(datalist)
        .on('input', event => {
            const search = input.value

            window.clearTimeout(timeout)

            if (controller) {
                controller.abort()
            }

            // Reset the list when user selected an autocomplete entry or the input is not long enough
            // Note: inputType is undefined in Chrome, and 'insertReplacementText' in Firefox
            if (!search.length || !event.inputType || event.inputType == 'insertReplacementText') {
                datalist.empty()
                return
            }

            // Execute the search (with a delay, to limit number of requests when the user is typing fast)
            timeout = window.setTimeout(() => search_request(search), 250)
        })
}

export {
    clearFormValidation,
    downloadFile,
    paymentCheckout,
    pick,
    startLoading,
    stopLoading,
    userAutocomplete
}
