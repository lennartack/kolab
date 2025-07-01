<template>
    <div class="container d-flex flex-column align-items-center justify-content-center">
        <div id="logon-form" class="card col-sm-8 col-lg-6">
            <div class="card-body p-4">
                <h1 class="card-title text-center mb-3">{{ $t('login.header') }}</h1>
                <div class="card-text m-2 mb-0">
                    <form class="form-signin" @submit.prevent="submit">
                        <div class="row mb-3">
                            <label for="email" class="visually-hidden">{{ $t('form.email') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><svg-icon icon="user"></svg-icon></span>
                                <input type="email" id="email" class="form-control" :placeholder="$t('form.email')" required autofocus v-model="email">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="password" class="visually-hidden">{{ $t('form.password') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><svg-icon icon="lock"></svg-icon></span>
                                <input type="password" id="password" class="form-control" :placeholder="$t('form.password')" required v-model="password">
                            </div>
                        </div>
                        <div class="row mb-3" v-if="$root.isUser">
                            <label for="secondfactor" class="visually-hidden">{{ $t('login.2fa') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><svg-icon icon="key"></svg-icon></span>
                                <input type="text" id="secondfactor" class="form-control rounded-end" :placeholder="$t('login.2fa')" v-model="secondfactor">
                            </div>
                            <small class="text-muted mt-2">{{ $t('login.2fa_desc') }}</small>
                        </div>
                        <p class="alert alert-danger d-flex align-items-center" role="alert" v-if="mode == 'expired-password'">
                            <svg-icon icon="circle-exclamation" class="fs-4 flex-shrink-0 me-2"></svg-icon> {{ $t('password.expired-text') }}
                        </p>
                        <div class="mb-4" v-if="mode == 'expired-password'">
                            <password-input class="mb-3" v-model="pass" :user="userId" placeholder="password.new" prefix="new_"></password-input>
                        </div>
                        <div class="text-center">
                            <btn class="btn-primary" type="submit" icon="right-to-bracket" :is-loading="loading">
                                {{ $t(loading ? 'login.signing_in' : 'login.sign_in') }}
                            </btn>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div id="logon-form-footer" class="mt-1">
            <router-link v-if="$root.isUser && $root.hasRoute('password-reset')" :to="{ name: 'password-reset' }" id="forgot-password">{{ $t('login.forgot_password') }}</router-link>
            <a v-if="webmailURL && $root.isUser" :href="webmailURL" id="webmail">{{ $t('login.webmail') }}</a>
        </div>
    </div>
</template>

<script>
    import PasswordInput from './Widgets/PasswordInput'

    import { library } from '@fortawesome/fontawesome-svg-core'

    library.add(
        require('@fortawesome/free-solid-svg-icons/faCircleExclamation').definition,
        require('@fortawesome/free-solid-svg-icons/faKey').definition,
        require('@fortawesome/free-solid-svg-icons/faLock').definition,
        require('@fortawesome/free-solid-svg-icons/faRightToBracket').definition,
    )

    export default {
        components: {
            PasswordInput
        },
        props: {
            dashboard: { type: Boolean, default: true }
        },
        data() {
            return {
                current: '',
                email: '',
                mode: 'login',
                pass: {},
                password: '',
                secondfactor: '',
                userId: '',
                webmailURL: window.config['app.webmail_url'],
                loading: false
            }
        },
        methods: {
            submit() {
                this.$root.clearFormValidation($('form.form-signin'))

                let url = 'api/auth/login'
                const post = this.$root.pick(this, ['email', 'password', 'secondfactor'])

                this.loading = true

                if (this.mode == 'expired-password') {
                    url = '/api/auth/password-reset-expired'
                    post.new_password = this.pass.password
                    post.new_password_confirmation = this.pass.password_confirmation
                }

                axios.post(url, post)
                    .then(response => {
                        // login user and redirect to dashboard
                        this.$root.loginUser(response.data, this.dashboard)
                        this.$emit('success')
                    })
                    .catch(error => {
                        if (error.status == 401 && error.response.data.password_expired) {
                            this.userId = error.response.data.id
                            this.mode = 'expired-password'
                            // We don't want email change at this point as it would not match
                            // userId, which is used for new password validity/policy checks
                            $('#email').prop('disabled', true)
                        }
                    })
                    .finally(() => {
                        this.loading = false
                    })
            }
        }
    }
</script>
