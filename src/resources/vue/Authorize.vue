<template>
    <div class="container d-flex flex-column align-items-center justify-content-center" id="auth-container">
        <div v-if="client" id="auth-form" class="card col-sm-8 col-lg-6">
            <div class="card-body p-4 text-center">
                <h1 class="card-title mb-3">{{ $t('auth.authorize-title', client) }}</h1>
                <div class="card-text m-2 mb-0">
                    <h6 id="auth-email" class="text-secondary mb-4">
                        <svg-icon icon="user" class="me-2"></svg-icon>{{ client.email }}
                    </h6>
                    <p id="auth-header">
                        {{ $t('auth.authorize-header', client) }}
                    </p>
                    <p>
                        <ul id="auth-claims" class="list-group text-start">
                            <li class="list-group-item" v-for="(item, idx) in client.claims" :key="idx">{{ item }}</li>
                        </ul>
                    </p>
                    <small id="auth-footer" class="text-secondary">
                        {{ $t('auth.authorize-footer', client) }}
                    </small>
                    <p class="mt-4">
                        <btn class="btn-success" icon="check" @click="allow">{{ $t('auth.allow') }}</btn>
                        <btn class="btn-danger ms-5" icon="xmark" @click="deny">{{ $t('auth.deny') }}</btn>
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
    import { library } from '@fortawesome/fontawesome-svg-core'

    library.add(
        require('@fortawesome/free-solid-svg-icons/faXmark').definition,
    )

    export default {
        data() {
            return {
                client: null,
            }
        },
        created() {
            this.submit(true)
        },
        methods: {
            allow() {
                this.submit()
            },
            deny() {
                if (this.client.url) {
                    this.redirect(this.client.url, { error: 'access_denied', state: this.$route.query.state })
                }
            },
            redirect(url, params) {
                // Merge additional parameters with the URL (that can already contain a search query)
                if (params) {
                    url = URL.parse(url)

                    const search = new URLSearchParams(url.searchParams)
                    for (const [k, v] of Object.entries(params)) {
                        search.set(k, v)
                    }

                    url.search = search
                }

                // Display loading widget, redirecting may take a while
                this.$root.startLoading(['#auth-container', { small: false, text: this.$t('msg.redirecting') }])

                // Follow the redirect to the external page
                window.location.href = url
            },
            submit(ifSeen = false) {
                let props = ['client_id', 'redirect_uri', 'state', 'nonce', 'scope', 'response_type', 'response_mode']
                let post = this.$root.pick(this.$route.query, props)
                let redirect = null

                post.ifSeen = ifSeen

                axios.post('/api/oauth/approve', post, { loading: true })
                    .then(response => {
                        if (response.data.status == 'prompt') {
                            // Display the form with Allow/Deny buttons
                            this.client = response.data.client
                            this.client.email = this.$root.authInfo.email
                        } else {
                            // Redirect to the external page
                            redirect = response.data
                        }
                    })
                    .catch(error => {
                        redirect = error.response.data
                        if (!redirect || !redirect.redirectUrl) {
                            this.$root.errorHandler(error)
                        }
                    })
                    .finally(() => {
                        if (redirect && redirect.redirectUrl) {
                            let params = this.$root.pick(redirect, ['error', 'error_description'])
                            params.state = this.$route.query.state

                            try {
                                params.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
                            } catch (e) {}

                            this.redirect(redirect.redirectUrl, params)
                        }
                    })
            }
        }
    }
</script>

