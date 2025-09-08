<template>
    <router-view v-if="!isLoading && !routerReloading && key" :key="key" @hook:mounted="childMounted"></router-view>
</template>

<script>
    export default {
        data() {
            return {
                isLoading: true,
                routerReloading: false
            }
        },
        computed: {
            key() {
                // Display 403 error page if the current user has no permission to a specified page
                // Note that it's the only place I found that allows us to do this.
                if (this.$route.meta.perm && !this.checkPermission(this.$route.meta.perm)) {
                    // Returning false here will block the page component from execution,
                    // as we're using the key in v-if condition on the router-view above
                    return false
                }

                // The 'key' property is used to reload the Page component
                // whenever a route changes. Normally vue does not do that.
                return this.$route.name == '404' ? this.$route.path : 'static'
            }
        },
        mounted() {
            const token = localStorage.getItem('token')

            if (token) {
                const post = { refresh_token: localStorage.getItem("refreshToken"), info: 1 }

                axios.post('/api/auth/refresh', post, { ignoreErrors: true, loader: true })
                    .then(response => {
                        this.$root.loginUser(response.data, false)
                    })
                    .catch(error => {
                        // Handle the error, on 401 display the logon page
                        this.$root.errorHandler(error)
                    })
                    .finally(() => {
                        // Release lock on the router-view, otherwise links (e.g. Logout) will not work
                        this.isLoading = false
                    })
            } else {
                this.isLoading = false
            }
        },
        methods: {
            checkPermission(type) {
                if (this.$root.hasPermission(type)) {
                    return true
                }

                const hint = type == 'wallets' ? this.$t('wallet.noperm') : ''

                this.$root.errorPage(403, null, hint)

                return false
            },
            childMounted() {
                this.$root.updateBodyClass()
                this.getFAQ()
                this.degradedWarning()
            },
            degradedWarning() {
                // Display "Account Degraded" warning on all pages
                if (this.$root.isDegraded()) {
                    let message = this.$t('user.degraded-warning')

                    if (this.$root.authInfo.isDegraded) {
                        message += ' ' + this.$t('user.degraded-hint')
                    }

                    const html = `<div id="status-degraded" class="d-flex justify-content-center">`
                        + `<p class="alert alert-danger">${message}</p></div>`

                    $('#app > div.container').prepend(html)
                }
            },
            getFAQ() {
                let page = this.$route.path

                if (page == '/' || page == '/login') {
                    return
                }

                axios.get('/content/faq' + page, { ignoreErrors: true })
                    .then(response => {
                        const result = response.data.faq
                        $('#faq').remove()
                        if (result && result.length) {
                            let faq = $('<div id="faq" class="faq mt-3"><h5>' + this.$t('app.faq') + '</h5><ul class="pl-4"></ul></div>')
                            let list = $([])

                            result.forEach(item => {
                                let li = $('<li>').append($('<a>').attr('href', item.href).text(item.title))

                                // Handle internal links with the vue-router
                                if (item.href.charAt(0) == '/') {
                                    li.find('a').on('click', event => {
                                        event.preventDefault()
                                        this.$router.push(item.href)
                                    })
                                }

                                list = list.add(li)
                            })

                            faq.find('ul').append(list)

                            $(this.$el).append(faq)
                        }
                    })
            },
            routerReload() {
                // Together with beforeRouteUpdate even on a route component
                // allows us to force reload the component. So it is possible
                // to jump from/to page that uses currently loaded component.
                this.routerReloading = true
                this.$nextTick().then(() => {
                    this.routerReloading = false
                })
            }
        }
    }
</script>
