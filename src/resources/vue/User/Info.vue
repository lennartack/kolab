<template>
    <div class="container">
        <status-component v-if="user_id !== 'new'" :status="status" @status-update="statusUpdate"></status-component>

        <div class="card" id="user-info">
            <div class="card-body">
                <div class="card-title" v-if="user_id === 'new'">{{ $t('user.new') }}</div>
                <div class="card-title" v-else>{{ $t($route.name == 'settings' ? 'dashboard.myaccount' : 'user.title') }}
                    <btn v-if="user.canDelete" icon="trash-can" class="btn-outline-danger button-delete float-end" @click="$refs.deleteWarning.show()">
                        {{ $t(isSelf ? 'user.profile-delete' : 'user.delete') }}
                    </btn>
                </div>
                <div class="card-text">
                    <tabs class="mt-3" :tabs="tabs" ref="tabs"></tabs>
                    <div class="tab-content">
                        <div class="tab-pane active" id="general" role="tabpanel" aria-labelledby="tab-general">
                            <form @submit.prevent="submit" class="card-body">
                                <div v-if="user_id !== 'new' && isController" class="row plaintext mb-3">
                                    <label for="status" class="col-sm-4 col-form-label">
                                        <span>{{ $t('form.status') }}</span>
                                        <span v-if="$route.name === 'settings'">&nbsp;({{ $t('user.custno') }})</span>
                                    </label>
                                    <div class="col-sm-8">
                                        <span class="form-control-plaintext">
                                            <span id="status" :class="$root.statusClass(user)">{{ $root.statusText(user) }}</span>
                                            <span id="userid" v-if="$route.name === 'settings'">&nbsp;({{ user_id }})</span>
                                        </span>
                                    </div>
                                </div>
                                <div class="row mb-3" v-if="user_id === 'new'">
                                    <label for="first_name" class="col-sm-4 col-form-label">{{ $t('form.firstname') }}</label>
                                    <div class="col-sm-8">
                                        <input type="text" class="form-control" id="first_name" v-model="user.first_name">
                                    </div>
                                </div>
                                <div class="row mb-3" v-if="user_id === 'new'">
                                    <label for="last_name" class="col-sm-4 col-form-label">{{ $t('form.lastname') }}</label>
                                    <div class="col-sm-8">
                                        <input type="text" class="form-control" id="last_name" v-model="user.last_name">
                                    </div>
                                </div>
                                <div class="row mb-3" v-if="user_id === 'new'">
                                    <label for="organization" class="col-sm-4 col-form-label">{{ $t('user.org') }}</label>
                                    <div class="col-sm-8">
                                        <input type="text" class="form-control" id="organization" v-model="user.organization">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <label for="email" class="col-sm-4 col-form-label">{{ $t('form.email') }}</label>
                                    <div class="col-sm-8">
                                        <input type="text" class="form-control" id="email" :disabled="user_id !== 'new'" required v-model="user.email">
                                    </div>
                                </div>
                                <div class="row mb-3" v-if="isController">
                                    <label for="aliases-input" class="col-sm-4 col-form-label">{{ $t('user.email-aliases') }}</label>
                                    <div class="col-sm-8">
                                        <list-input id="aliases" :list="user.aliases"></list-input>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <label for="password" class="col-sm-4 col-form-label">
                                        {{ $t('form.password') }}
                                        <btn v-if="user.password_expired" class="btn-link btn-lg text-warning p-0" icon="circle-exclamation" v-tooltip="$t('password.expired-on', { date: user.password_expired})"></btn>
                                    </label>
                                    <div class="col-sm-8">
                                        <div v-if="!isSelf" class="btn-group w-100" role="group">
                                            <input type="checkbox" id="pass-mode-input" value="input" class="btn-check" @change="setPasswordMode" :checked="passwordMode == 'input'">
                                            <label class="btn btn-outline-secondary" for="pass-mode-input">{{ $t('user.pass-input') }}</label>
                                            <input type="checkbox" id="pass-mode-link" value="link" class="btn-check" @change="setPasswordMode">
                                            <label class="btn btn-outline-secondary" for="pass-mode-link">{{ $t('user.pass-link') }}</label>
                                        </div>
                                        <password-input v-if="passwordMode == 'input'" :class="isSelf ? '' : 'mt-2'" v-model="user"></password-input>
                                        <div id="password-link" v-if="isController && (passwordMode == 'link' || user.passwordLinkCode)" class="mt-2">
                                            <span>{{ $t('user.pass-link-label') }}</span>&nbsp;<code>{{ passwordLink }}</code>
                                            <span class="d-inline-block">
                                                <btn class="btn-link p-1" :icon="['far', 'clipboard']" :title="$t('btn.copy')" @click="passwordLinkCopy"></btn>
                                                <btn v-if="user.passwordLinkCode" class="btn-link text-danger p-1" icon="trash-can" :title="$t('btn.delete')" @click="passwordLinkDelete"></btn>
                                            </span>
                                            <div v-if="!user.passwordLinkCode" class="form-text m-0">{{ $t('user.pass-link-hint') }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="user_id === 'new'" id="user-packages" class="row mb-3">
                                    <label class="col-sm-4 col-form-label">{{ $t('user.package') }}</label>
                                    <package-select class="col-sm-8 pt-sm-1"></package-select>
                                </div>
                                <div v-if="user_id !== 'new' && $root.hasPermission('subscriptions')" id="user-skus" class="row mb-3">
                                    <label class="col-sm-4 col-form-label">{{ $t('form.subscriptions') }}</label>
                                    <subscription-select v-if="user.id" class="col-sm-8 pt-sm-1" :object="user" ref="skus"></subscription-select>
                                </div>
                                <btn class="btn-primary" type="submit" icon="check">{{ $t('btn.submit') }}</btn>
                                <btn id="button-login-as" class="btn-outline-secondary float-end" @click="loginAs" v-if="$route.name != 'settings' && user_id !== 'new' && $root.hasPermission('loginAs')">
                                    {{ $t('user.login-as') }}
                                </btn>
                            </form>
                        </div>
                        <div v-if="Object.keys(settingsSections).length > 0" class="tab-pane" id="settings" role="tabpanel" aria-labelledby="tab-settings">
                            <accordion class="mt-3" id="settings-all" :names="settingsSections" :buttons="settingsButtons">
                                <template #options v-if="settingsSections.options">
                                    <form @submit.prevent="submitSettings">
                                        <div v-if="$root.hasPermission('beta')" class="row checkbox mb-3">
                                            <label for="guam_enabled" class="col-sm-4 col-form-label">
                                                {{ $t('user.imapproxy') }}
                                                <sup class="badge bg-primary">{{ $t('dashboard.beta') }}</sup>
                                            </label>
                                            <div class="col-sm-8 pt-2">
                                                <input type="checkbox" id="guam_enabled" name="guam_enabled" value="1" class="form-check-input d-block mb-2" :checked="user.config.guam_enabled">
                                                <small id="guam-hint" class="text-muted">
                                                    {{ $t('user.imapproxy-text') }}
                                                </small>
                                            </div>
                                        </div>
                                        <div v-if="$root.hasPermission('beta')" class="row mb-3">
                                            <label for="limit_geo" class="col-sm-4 col-form-label">
                                                {{ $t('user.geolimit') }}
                                                <sup class="badge bg-primary">{{ $t('dashboard.beta') }}</sup>
                                            </label>
                                            <div class="col-sm-8 pt-2">
                                                <country-select id="limit_geo" v-model="user.config.limit_geo"></country-select>
                                                <small id="geolimit-hint" class="text-muted">
                                                    {{ $t('user.geolimit-text') }}
                                                </small>
                                            </div>
                                        </div>
                                        <btn class="btn-primary" type="submit" icon="check">{{ $t('btn.submit') }}</btn>
                                    </form>
                                </template>
                                <template #maildelivery v-if="settingsSections.maildelivery">
                                    <form @submit.prevent="submitMailDelivery">
                                        <div class="row mb-3">
                                            <label for="greylist_enabled" class="col-sm-4 col-form-label">{{ $t('policies.greylist') }}</label>
                                            <div class="col-sm-8">
                                                <select id="greylist_enabled" name="greylist" class="form-select">
                                                    <option value="" :selected="user.config.greylist_enabled == null">{{ $t('form.default') }} ({{ $t(user.config.greylist_policy ? 'form.enabled' : 'form.disabled') }})</option>
                                                    <option value="true" :selected="user.config.greylist_enabled === true">{{ $t('form.enabled') }}</option>
                                                    <option value="false" :selected="user.config.greylist_enabled === false">{{ $t('form.disabled') }}</option>
                                                </select>
                                                <small id="greylisting-hint" class="text-muted">
                                                    {{ $t('policies.greylist-text') }}
                                                </small>
                                            </div>
                                        </div>
                                        <div class="row mb-3" v-if="$root.authInfo.statusInfo.enableMailfilter">
                                            <label for="itip_config" class="col-sm-4 col-form-label">{{ $t('policies.calinvitations') }}</label>
                                            <div class="col-sm-8">
                                                <select id="itip_config" name="itip" class="form-select">
                                                    <option value="" :selected="user.config.itip_config == null">{{ $t('form.default') }} ({{ $t(user.itip_policy ? 'form.enabled' : 'form.disabled') }})</option>
                                                    <option value="true" :selected="user.config.itip_config === true">{{ $t('form.enabled') }}</option>
                                                    <option value="false" :selected="user.config.itip_config === false">{{ $t('form.disabled') }}</option>
                                                </select>
                                                <small id="itip-hint" class="text-muted">
                                                    {{ $t('policies.calinvitations-text') }}
                                                </small>
                                            </div>
                                        </div>
                                        <div class="row mb-3" v-if="$root.authInfo.statusInfo.enableMailfilter">
                                            <label for="externalsender_config" class="col-sm-4 col-form-label">{{ $t('policies.extsender') }}</label>
                                            <div class="col-sm-8">
                                                <select id="externalsender_config" name="extsender" class="form-select">
                                                    <option value="" :selected="user.config.externalsender_config == null">{{ $t('form.default') }} ({{ $t(user.config.externalsender_policy ? 'form.enabled' : 'form.disabled') }})</option>
                                                    <option value="true" :selected="user.config.externalsender_config === true">{{ $t('form.enabled') }}</option>
                                                    <option value="false" :selected="user.config.externalsender_config === false">{{ $t('form.disabled') }}</option>
                                                </select>
                                                <small id="externalsender-hint" class="text-muted">
                                                    {{ $t('policies.extsender-text') }}
                                                </small>
                                            </div>
                                        </div>
                                        <btn class="btn-primary" type="submit" icon="check">{{ $t('btn.submit') }}</btn>
                                    </form>
                                </template>
                                <template #delegation v-if="settingsSections.delegation">
                                    <list-table :list="delegations" :setup="delegationListSetup" class="mb-0">
                                        <template #email="{ item }">
                                            <svg-icon icon="user-tie"></svg-icon>&nbsp;<span>{{ item.email }}</span>
                                        </template>
                                        <template #buttons="{ item }">
                                            <btn class="text-danger button-delete p-0 ms-1" @click="delegationDelete(item.email)" icon="trash-can" :title="$t('btn.delete')"></btn>
                                        </template>
                                    </list-table>
                                </template>
                            </accordion>
                        </div>
                        <div class="tab-pane" id="personal" role="tabpanel" aria-labelledby="tab-personal">
                            <form @submit.prevent="submitPersonalSettings" class="card-body">
                                <div class="row mb-3">
                                    <label for="first_name" class="col-sm-4 col-form-label">{{ $t('form.firstname') }}</label>
                                    <div class="col-sm-8">
                                        <input type="text" class="form-control" id="first_name" v-model="user.first_name">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <label for="last_name" class="col-sm-4 col-form-label">{{ $t('form.lastname') }}</label>
                                    <div class="col-sm-8">
                                        <input type="text" class="form-control" id="last_name" v-model="user.last_name">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <label for="organization" class="col-sm-4 col-form-label">{{ $t('user.org') }}</label>
                                    <div class="col-sm-8">
                                        <input type="text" class="form-control" id="organization" v-model="user.organization">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <label for="phone" class="col-sm-4 col-form-label">{{ $t('form.phone') }}</label>
                                    <div class="col-sm-8">
                                        <input type="text" class="form-control" id="phone" v-model="user.phone">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <label for="external_email" class="col-sm-4 col-form-label">{{ $t('user.ext-email') }}</label>
                                    <div class="col-sm-8">
                                        <input type="text" class="form-control" id="external_email" v-model="user.external_email">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <label for="billing_address" class="col-sm-4 col-form-label">{{ $t('user.address') }}</label>
                                    <div class="col-sm-8">
                                        <textarea class="form-control" id="billing_address" rows="3" v-model="user.billing_address"></textarea>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <label for="country" class="col-sm-4 col-form-label">{{ $t('user.country') }}</label>
                                    <div class="col-sm-8">
                                        <select class="form-select" id="country" v-model="user.country">
                                            <option value="">-</option>
                                            <option v-for="(item, code) in countries" :value="code" :key="code">{{ item[1] }}</option>
                                        </select>
                                    </div>
                                </div>
                                <btn class="btn-primary button-submit mt-2" type="submit" icon="check">{{ $t('btn.submit') }}</btn>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <modal-dialog id="delete-warning" ref="deleteWarning" :buttons="[deleteButton]" :cancel-focus="true" @click="deleteUser()"
                      :title="$t(isSelf ? 'user.profile-delete-title' : 'user.delete-email', { email: user.email })"
        >
            <div v-if="isSelf">
                <p>{{ $t('user.profile-delete-text1') }} <strong>{{ $t('user.profile-delete-warning') }}</strong>.</p>
                <p>{{ $t('user.profile-delete-text2') }}</p>
                <p v-if="supportEmail" v-html="$t('user.profile-delete-support', { href: 'mailto:' + supportEmail, email: supportEmail })"></p>
                <p>{{ $t('user.profile-delete-contact', { app: $root.appName }) }}</p>
            </div>
            <p v-else>{{ $t('user.delete-text') }}</p>
        </modal-dialog>
        <modal-dialog id="delegation-create" ref="delegationDialog" :buttons="['save']" @click="delegationCreate()" :title="$t('user.delegation-create')">
            <form class="card-body" data-validation-prefix="delegation-">
                <div class="row mb-3">
                    <label for="delegation-email" class="col-sm-4 col-form-label">{{ $t('form.user') }}</label>
                    <div class="col-sm-8">
                        <input type="text" class="form-control" id="delegation-email" v-model="delegatee" :placeholder="$t('form.email')">
                    </div>
                </div>
                <div class="row">
                    <label class="col-form-label">{{ $t('user.delegation-perm') }}</label>
                </div>
                <div class="row mb-2" v-for="(icon, type) in delegationTypes" :key="`delegation-${type}-row`">
                    <label for="delegation-" class="col-4 col-form-label">
                        <svg-icon :icon="icon" class="fs-3 me-2" style="width:1em"></svg-icon>
                        <span class="align-text-bottom">{{ $t(`user.delegation-${type}`) }}</span>
                    </label>
                    <div class="col-8">
                        <select type="text" class="form-select" :id="`delegation-${type}`">
                            <option value="" selected>- {{ $t('form.none') }} -</option>
                            <option value="read-only">{{ $t('form.acl-read-only') }}</option>
                            <option value="read-write">{{ $t('form.acl-read-write') }}</option>
                        </select>
                    </div>
                </div>
                <div class="row form-text"><span>{{ $t('user.delegation-desc') }}</span></div>
            </form>
        </modal-dialog>
    </div>
</template>

<script>
    import Accordion from '../Widgets/Accordion'
    import CountrySelect from '../Widgets/CountrySelect'
    import ListInput from '../Widgets/ListInput'
    import { ListTable } from '../Widgets/ListTools'
    import ModalDialog from '../Widgets/ModalDialog'
    import PackageSelect from '../Widgets/PackageSelect'
    import PasswordInput from '../Widgets/PasswordInput'
    import StatusComponent from '../Widgets/Status'
    import SubscriptionSelect from '../Widgets/SubscriptionSelect'

    import { library } from '@fortawesome/fontawesome-svg-core'

    library.add(
        require('@fortawesome/free-regular-svg-icons/faClipboard').definition,
        require('@fortawesome/free-solid-svg-icons/faCalendarCheck').definition,
        require('@fortawesome/free-solid-svg-icons/faCalendarDays').definition,
        require('@fortawesome/free-solid-svg-icons/faEnvelope').definition,
        require('@fortawesome/free-solid-svg-icons/faUserTie').definition,
        require('@fortawesome/free-solid-svg-icons/faUsers').definition,
    )

    export default {
        components: {
            Accordion,
            CountrySelect,
            ListInput,
            ListTable,
            ModalDialog,
            PackageSelect,
            PasswordInput,
            StatusComponent,
            SubscriptionSelect
        },
        data() {
            return {
                countries: window.config.countries,
                delegatee: null,
                delegations: null,
                delegationListSetup: {
                    buttons: true,
                    columns: [
                        {
                            prop: 'email',
                            contentSlot: 'email'
                        },
                    ],
                    footLabel: 'user.delegation-none'
                },
                delegationTypes: {
                    mail: 'envelope',
                    event: 'calendar-days',
                    task: 'calendar-check',
                    contact: 'users'
                },
                isSelf: false,
                passwordLinkCode: '',
                passwordMode: '',
                user_id: null,
                user: { aliases: [], config: [] },
                settingsButtons: {
                    delegation: [
                        {
                            icon: 'user-tie',
                            label: this.$t('user.delegation-create'),
                            click: () => this.$refs.delegationDialog.show()
                        }
                    ],
                },
                supportEmail: window.config['app.support_email'],
                status: {},
                successRoute: { name: 'users' }
            }
        },
        computed: {
            deleteButton: function () {
                return {
                    className: 'btn-danger modal-action',
                    dismiss: 'modal',
                    label: this.isSelf ? 'user.profile-delete' : 'btn.delete',
                    icon: 'trash-can'
                }
            },
            settingsSections: function () {
                let opts = {}
                if (this.isController) {
                    if (this.$root.hasPermission('beta')) {
                        opts.options = this.$t('form.mainopts')
                    }
                    opts.maildelivery = this.$t('policies.mailDelivery')
                }
                if ((this.isController || this.isSelf) && this.$root.hasPermission('delegation')) {
                    opts.delegation = this.$t('user.delegation')
                }
                return opts
            },
            isController: function () {
                return this.$root.hasPermission('users')
            },
            passwordLink: function () {
                return this.$root.appUrl + '/password-reset/' + this.passwordLinkCode
            },
            tabs: function () {
                let tabs = ['form.general']

                if (this.user_id === 'new') {
                    return tabs
                }

                if (Object.keys(this.settingsSections).length > 0) {
                    tabs.push('form.settings')
                }

                tabs.push('form.personal')

                return tabs
            }
        },
        created() {
            if (this.$route.name === 'settings') {
                this.user_id = this.$root.authInfo.id
                this.successRoute = null
            } else {
                this.user_id = this.$route.params.user
            }

            this.isSelf = this.user_id == this.$root.authInfo.id

            if (this.user_id !== 'new') {
                axios.get('/api/v4/users/' + this.user_id, { loader: true })
                    .then(response => {
                        // A controller cannot edit the account owner
                        if (this.user_id != this.$root.authInfo.id && this.user_id == response.data.wallet.user_id) {
                            // TODO: Instead of an error page we should consider a read-only page
                            this.$root.errorPage(403)
                            return
                        }

                        this.user = { ...response.data, ...response.data.settings }
                        this.status = response.data.statusInfo
                        this.passwordLinkCode = this.user.passwordLinkCode
                    })
                    .catch(this.$root.errorHandler)

                if (this.isSelf) {
                    this.passwordMode = 'input'
                }
            } else {
                this.passwordMode = 'input'
            }
        },
        mounted() {
            $('#first_name').focus()

            if (this.settingsSections.delegation) {
                this.$refs.tabs.clickHandler('settings', () => {
                    if (this.delegations === null) {
                        this.delegationList()
                    }
                })
            }

            this.$refs.delegationDialog.events({
                show: (event) => {
                    this.delegatee = null
                }
            })
        },
        methods: {
            passwordLinkCopy() {
                navigator.clipboard.writeText($('#password-link code').text());
            },
            passwordLinkDelete() {
                this.passwordMode = ''
                $('#pass-mode-link')[0].checked = false

                // Delete the code for real
                axios.delete('/api/v4/password-reset/code/' + this.passwordLinkCode)
                    .then(response => {
                        this.passwordLinkCode = ''
                        this.user.passwordLinkCode = ''
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                        }
                    })
            },
            setPasswordMode(event) {
                const mode = event.target.checked ? event.target.value : ''

                // In the "new user" mode the password mode cannot be unchecked
                if (!mode && this.user_id === 'new') {
                    event.target.checked = true
                    return
                }

                this.passwordMode = mode

                if (!event.target.checked) {
                    return
                }

                $('#pass-mode-' + (mode == 'link' ? 'input' : 'link'))[0].checked = false

                // Note: we use $nextTick() because we have to wait for the HTML elements to exist
                this.$nextTick().then(() => {
                    if (mode == 'link' && !this.passwordLinkCode) {
                        axios.post('/api/v4/password-reset/code', {}, { loader: '#password-link' })
                            .then(response => {
                                this.passwordLinkCode = response.data.short_code + '-' + response.data.code
                            })
                    } else if (mode == 'input') {
                        $('#password').focus();
                    }
                })
            },
            submit() {
                this.$root.clearFormValidation($('#general form'))

                let props = this.isController ? ['aliases'] : []
                if (this.user_id === 'new') {
                    props = props.concat(['email', 'first_name', 'last_name', 'organization'])
                }

                let method = 'post'
                let location = '/api/v4/users'
                let post = this.$root.pick(this.user, props)

                if (this.user_id !== 'new') {
                    method = 'put'
                    location += '/' + this.user_id
                    if (this.$refs.skus) {
                        post.skus = this.$refs.skus.getSkus()
                    }
                } else {
                    post.package = $('#user-packages input:checked').val()
                }

                if (this.passwordMode == 'link' && this.passwordLinkCode) {
                    post.passwordLinkCode = this.passwordLinkCode
                } else if (this.passwordMode == 'input') {
                    post.password = this.user.password
                    post.password_confirmation = this.user.password_confirmation
                }

                axios[method](location, post)
                    .then(response => {
                        if (response.data.statusInfo) {
                            this.$root.authInfo.statusInfo = response.data.statusInfo
                        }

                        this.$toast.success(response.data.message)
                        if (this.successRoute) {
                            this.$router.push(this.successRoute)
                        }
                    })
            },
            submitMailDelivery() {
                this.$root.clearFormValidation('#maildelivery form')

                const typeMap = { 'true': true, 'false': false }
                let post = {}

                $('#maildelivery form').find('select').each(function() {
                    post[this.id] = this.value in typeMap ? typeMap[this.value] : null
                })

                axios.post('/api/v4/users/' + this.user_id + '/config', post)
                    .then(response => {
                        this.$toast.success(response.data.message)
                    })
            },
            submitPersonalSettings() {
                this.$root.clearFormValidation($('#personal form'))

                let post = this.$root.pick(this.user, ['first_name', 'last_name', 'organization', 'phone', 
                    'country', 'external_email', 'billing_address'])

                axios.put('/api/v4/users' + '/' + this.user_id, post)
                    .then(response => {
                        if (response.data.statusInfo) {
                            this.$root.authInfo.statusInfo = response.data.statusInfo
                        }

                        this.$toast.success(response.data.message)
                        if (this.successRoute) {
                            this.$router.push(this.successRoute)
                        }
                    })
            },
            submitSettings() {
                this.$root.clearFormValidation($('#settings form'))

                let post = this.$root.pick(this.user.config, ['limit_geo'])
                const names = ['guam_enabled']

                names.forEach(name => {
                    if ($('#' + name).length) {
                        post[name] = $('#' + name).prop('checked') ? 1 : 0
                    }
                })

                axios.post('/api/v4/users/' + this.user_id + '/config', post)
                    .then(response => {
                        this.$toast.success(response.data.message)
                    })
            },
            statusUpdate(user) {
                this.user = Object.assign({}, this.user, user)
            },
            delegationCreate() {
                let post = {email: this.delegatee, options: {}}

                $('#delegation-create select').each(function () {
                    post.options[this.id.split('-')[1]] = this.value;
                });

                axios.post('/api/v4/users/' + this.user_id + '/delegations', post)
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.$refs.delegationDialog.hide();
                            this.delegationList(true)
                        }
                    })
            },
            delegationDelete(email) {
                axios.delete('/api/v4/users/' + this.user_id + '/delegations/' + email)
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.delegationList(true)
                        }
                    })
            },
            delegationList(reset) {
                if (reset) {
                    this.delegations = null
                }
                axios.get('/api/v4/users/' + this.user_id + '/delegations', { loader: '#delegation' })
                    .then(response => {
                        this.delegations = response.data.list
                    })
            },
            deleteUser() {
                // Delete the user from the confirm dialog
                axios.delete('/api/v4/users/' + this.user_id)
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)

                            if (this.isSelf) {
                                this.$root.logoutUser()
                            } else {
                                this.$router.push(this.successRoute)
                            }
                        }
                    })
            },
            loginAs() {
                axios.post('/api/v4/users/' + this.user_id + '/login-as')
                   .then(response => {
                       if (response.data.redirectUrl) {
                           window.open(response.data.redirectUrl)
                       }
                   })
           }
        }
    }
</script>
