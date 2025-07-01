<template>
    <div class="container">
        <div class="card" id="user-info">
            <div class="card-body">
                <h1 class="card-title">{{ user.email }}</h1>
                <div class="card-text">
                    <form class="read-only short">
                        <div v-if="user.wallet.user_id != user.id" class="row plaintext">
                            <label for="manager" class="col-sm-4 col-form-label">{{ $t('user.managed-by') }}</label>
                            <div class="col-sm-8">
                                <span class="form-control-plaintext" id="manager">
                                    <router-link :to="{ path: '/user/' + user.wallet.user_id }">{{ user.wallet.user_email }}</router-link>
                                </span>
                            </div>
                        </div>
                        <div class="row plaintext">
                            <label for="userid" class="col-sm-4 col-form-label">ID <span class="text-muted">({{ $t('form.created') }})</span></label>
                            <div class="col-sm-8">
                                <span class="form-control-plaintext" id="userid">
                                    {{ user.id }} <span class="text-muted">({{ user.created_at }})</span>
                                </span>
                            </div>
                        </div>
                        <div class="row plaintext">
                            <label for="status" class="col-sm-4 col-form-label">{{ $t('form.status') }}</label>
                            <div class="col-sm-8">
                                <span class="form-control-plaintext" id="status">
                                    <span :class="$root.statusClass(user)">{{ $root.statusText(user) }}</span>
                                    <span v-if="user.isRestricted" class="badge bg-primary rounded-pill ms-1">{{ $t('status.restricted') }}</span>
                                    <small v-if="user.settings.password_expired" class="d-block text-danger">{{ $t('password.expired-on', { date: user.settings.password_expired }) }}</small>
                                </span>
                            </div>
                        </div>
                        <div class="row plaintext" v-if="user.first_name">
                            <label for="first_name" class="col-sm-4 col-form-label">{{ $t('form.firstname') }}</label>
                            <div class="col-sm-8">
                                <span class="form-control-plaintext" id="first_name">{{ user.first_name }}</span>
                            </div>
                        </div>
                        <div class="row plaintext" v-if="user.last_name">
                            <label for="last_name" class="col-sm-4 col-form-label">{{ $t('form.lastname') }}</label>
                            <div class="col-sm-8">
                                <span class="form-control-plaintext" id="last_name">{{ user.last_name }}</span>
                            </div>
                        </div>
                        <div class="row plaintext" v-if="user.organization">
                            <label for="organization" class="col-sm-4 col-form-label">{{ $t('user.org') }}</label>
                            <div class="col-sm-8">
                                <span class="form-control-plaintext" id="organization">{{ user.organization }}</span>
                            </div>
                        </div>
                        <div class="row plaintext" v-if="user.phone">
                            <label for="phone" class="col-sm-4 col-form-label">{{ $t('form.phone') }}</label>
                            <div class="col-sm-8">
                                <span class="form-control-plaintext" id="phone">{{ user.phone }}</span>
                            </div>
                        </div>
                        <div class="row plaintext">
                            <label for="external_email" class="col-sm-4 col-form-label">{{ $t('user.ext-email') }}</label>
                            <div class="col-sm-8">
                                <span class="form-control-plaintext" id="external_email">
                                    <a v-if="user.external_email" :href="'mailto:' + user.external_email">{{ user.external_email }}</a>
                                    <btn class="btn-secondary btn-sm ms-2" @click="emailEdit">{{ $t('btn.edit') }}</btn>
                                </span>
                            </div>
                        </div>
                        <div class="row plaintext" v-if="user.billing_address">
                            <label for="billing_address" class="col-sm-4 col-form-label">{{ $t('user.address') }}</label>
                            <div class="col-sm-8">
                                <span class="form-control-plaintext" style="white-space:pre" id="billing_address">{{ user.billing_address }}</span>
                            </div>
                        </div>
                        <div class="row plaintext">
                            <label for="country" class="col-sm-4 col-form-label">{{ $t('user.country') }}</label>
                            <div class="col-sm-8">
                                <span class="form-control-plaintext" id="country">{{ user.country }}</span>
                            </div>
                        </div>
                    </form>
                    <div class="mt-2 buttons">
                        <btn :id="`button-${suspendAction}`" class="btn-outline-primary" @click="setSuspendState">
                            {{ $t(`btn.${suspendAction}`) }}
                        </btn>
                        <btn id="button-resync" class="btn-outline-primary" @click="resyncUser">
                            {{ $t('btn.resync') }}
                        </btn>
                    </div>
                </div>
            </div>
        </div>
        <tabs class="mt-3" :tabs="tabs" ref="tabs"></tabs>
        <div class="tab-content">
            <div class="tab-pane show active" id="finances" role="tabpanel" aria-labelledby="tab-finances">
                <div class="card-body">
                    <h2 class="card-title">
                        {{ $t('wallet.title') }}
                        <span :class="wallet.balance < 0 ? 'text-danger' : 'text-success'"><strong>{{ $root.price(wallet.balance, wallet.currency) }}</strong></span>
                    </h2>
                    <div class="card-text">
                        <form class="read-only short">
                            <div class="row">
                                <label class="col-sm-4 col-form-label">{{ $t('user.discount') }}</label>
                                <div class="col-sm-8">
                                    <span class="form-control-plaintext" id="discount">
                                        <span>{{ wallet.discount ? (wallet.discount + '% - ' + wallet.discount_description) : 'none' }}</span>
                                        <btn class="btn-secondary btn-sm ms-2" @click="discountEdit">{{ $t('btn.edit') }}</btn>
                                    </span>
                                </div>
                            </div>
                            <div class="row" v-if="wallet.mandate && wallet.mandate.id">
                                <label class="col-sm-4 col-form-label">{{ $t('user.auto-payment') }}</label>
                                <div class="col-sm-8">
                                    <span id="autopayment" :class="'form-control-plaintext' + (wallet.mandateState ? ' text-danger' : '')"
                                          v-html="$t('user.auto-payment-text', {
                                              amount: wallet.mandate.amount + ' ' + wallet.currency,
                                              balance: wallet.mandate.balance + ' ' + wallet.currency,
                                              method: wallet.mandate.method
                                          })"
                                    >
                                        <span v-if="wallet.mandateState">({{ wallet.mandateState }})</span>.
                                    </span>
                                </div>
                            </div>
                            <div class="row" v-if="wallet.providerLink">
                                <label class="col-sm-4 col-form-label">{{ capitalize(wallet.provider) }} {{ $t('form.id') }}</label>
                                <div class="col-sm-8">
                                    <span class="form-control-plaintext" v-html="wallet.providerLink"></span>
                                </div>
                            </div>
                        </form>
                        <div class="mt-2 buttons">
                            <btn id="button-award" class="btn-success" @click="awardDialog">{{ $t('user.add-bonus') }}</btn>
                            <btn id="button-penalty" class="btn-danger" @click="penalizeDialog">{{ $t('user.add-penalty') }}</btn>
                        </div>
                    </div>
                    <h2 class="card-title mt-4">{{ $t('wallet.transactions') }}</h2>
                    <transaction-log v-if="wallet.id && !walletReload" class="card-text" :wallet-id="wallet.id" :is-admin="true"></transaction-log>
                </div>
            </div>
            <div class="tab-pane" id="receipts" role="tabpanel" aria-labelledby="tab-receipts">
                <div class="card-body">
                    <div class="card-text">
                        <receipt-list v-if="wallet.id && !walletReload" class="card-text" :wallet-id="wallet.id"></receipt-list>
                    </div>
                </div>
            </div>
            <div class="tab-pane" id="aliases" role="tabpanel" aria-labelledby="tab-aliases">
                <div class="card-body">
                    <div class="card-text">
                        <list-table :list="user.aliases" :setup="aliasesListSetup" class="mb-0"></list-table>
                    </div>
                </div>
            </div>
            <div class="tab-pane" id="subscriptions" role="tabpanel" aria-labelledby="tab-subscriptions">
                <div class="card-body">
                    <div class="card-text">
                        <list-table :list="skus" :setup="skusListSetup" class="mb-0"></list-table>
                        <small v-if="discount > 0" class="hint">
                            <hr class="m-0">
                            &sup1; {{ $t('user.discount-hint') }}: {{ discount }}% - {{ discount_description }}
                        </small>
                        <div class="mt-2 buttons">
                            <btn class="btn-danger" id="reset2fa" v-if="has2FA" @click="$refs.reset2faDialog.show()">{{ $t('user.reset-2fa') }}</btn>
                            <btn class="btn-secondary" id="addbetasku" v-if="!hasBeta" @click="addBetaSku">{{ $t('user.add-beta') }}</btn>
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane" id="domains" role="tabpanel" aria-labelledby="tab-domains">
                <div class="card-body">
                    <div class="card-text">
                        <domain-list :list="domains" class="mb-0"></domain-list>
                    </div>
                </div>
            </div>
            <div class="tab-pane" id="users" role="tabpanel" aria-labelledby="tab-users">
                <div class="card-body">
                    <div class="card-text">
                        <user-list :list="users" :current="user" class="mb-0"></user-list>
                    </div>
                </div>
            </div>
            <div class="tab-pane" id="distlists" role="tabpanel" aria-labelledby="tab-distlists">
                <div class="card-body">
                    <div class="card-text">
                        <distlist-list :list="distlists" class="mb-0"></distlist-list>
                    </div>
                </div>
            </div>
            <div class="tab-pane" id="resources" role="tabpanel" aria-labelledby="tab-resources">
                <div class="card-body">
                    <div class="card-text">
                        <resource-list :list="resources" class="mb-0"></resource-list>
                    </div>
                </div>
            </div>
            <div class="tab-pane" id="folders" role="tabpanel" aria-labelledby="tab-folders">
                <div class="card-body">
                    <div class="card-text">
                        <shared-folder-list :list="folders" :with-email="true" class="mb-0"></shared-folder-list>
                    </div>
                </div>
            </div>
            <div class="tab-pane" id="settings" role="tabpanel" aria-labelledby="tab-settings">
                <div class="card-body">
                    <div class="card-text">
                        <form class="read-only short">
                            <div class="row plaintext">
                                <label for="greylist_enabled" class="col-sm-4 col-form-label">{{ $t('policies.greylist') }}</label>
                                <div class="col-sm-8">
                                    <span class="form-control-plaintext" id="greylist_enabled">
                                        <span v-if="user.config.greylist_enabled" class="text-success">{{ $t('form.enabled') }}</span>
                                        <span v-else class="text-danger">{{ $t('form.disabled') }}</span>
                                    </span>
                                </div>
                            </div>
                            <div class="row plaintext">
                                <label for="guam_enabled" class="col-sm-4 col-form-label">{{ $t('user.imapproxy') }}</label>
                                <div class="col-sm-8">
                                    <span class="form-control-plaintext" id="guam_enabled">
                                        <span v-if="user.config.guam_enabled" class="text-success">{{ $t('form.enabled') }}</span>
                                        <span v-else class="text-danger">{{ $t('form.disabled') }}</span>
                                    </span>
                                </div>
                            </div>
                            <div class="row plaintext">
                                <label for="limit_geo" class="col-sm-4 col-form-label">{{ $t('user.geolimit') }}</label>
                                <div class="col-sm-8">
                                    <span class="form-control-plaintext" id="limit_geo">
                                        {{ $root.countriesText(user.config.limit_geo) }}
                                    </span>
                                    <btn v-if="user.config.limit_geo && user.config.limit_geo.length" class="btn-secondary btn-sm ms-2" @click="resetGeoLock">{{ $t('btn.reset') }}</btn>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="tab-pane" id="history" role="tabpanel" aria-labelledby="tab-history">
                <div class="card-body">
                    <event-log v-if="loadEventLog" :object-id="user.id" object-type="user" ref="eventLog" class="card-text mb-0"></event-log>
                </div>
            </div>
        </div>

        <modal-dialog id="discount-dialog" ref="discountDialog" :title="$t('user.discount-title')" @click="submitDiscount()" :buttons="['submit']">
            <select v-model="wallet.discount_id" class="form-select">
                <option value="">- {{ $t('form.none') }} -</option>
                <option v-for="item in discounts" :value="item.id" :key="item.id">{{ item.label }}</option>
            </select>
        </modal-dialog>

        <modal-dialog id="email-dialog" ref="emailDialog" :title="$t('user.ext-email')" @click="submitEmail()" :buttons="['submit']">
            <input v-model="external_email" name="external_email" class="form-control">
        </modal-dialog>

        <modal-dialog id="suspend-dialog" ref="suspendDialog" :title="$t(`btn.${suspendAction}`)" @click="submitSuspend()" :buttons="['submit']">
            <textarea v-model="comment" name="comment" class="form-control" :placeholder="$t('form.comment')" rows="3"></textarea>
        </modal-dialog>

        <modal-dialog id="oneoff-dialog" ref="oneoffDialog" @click="submitOneOff()" :buttons="['submit']"
                      :title="$t(oneoff_negative ? 'user.add-penalty-title' : 'user.add-bonus-title')"
        >
            <form data-validation-prefix="oneoff_">
                <div class="mb-3">
                    <label for="oneoff_amount" class="form-label">{{ $t('form.amount') }}</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="oneoff_amount" v-model="oneoff_amount" required>
                        <span class="input-group-text">{{ wallet.currency }}</span>
                    </div>
                </div>
                <div>
                    <label for="oneoff_description" class="form-label">{{ $t('form.description') }}</label>
                    <input class="form-control" id="oneoff_description" v-model="oneoff_description" required>
                </div>
            </form>
        </modal-dialog>

        <modal-dialog id="reset-2fa-dialog" ref="reset2faDialog" :title="$t('user.reset-2fa-title')" @click="reset2FA()"
                      :buttons="[{className: 'btn-danger modal-action', label: 'btn.reset'}]"
        >
            <p>{{ $t('user.2fa-hint1') }}</p>
            <p>{{ $t('user.2fa-hint2') }}</p>
        </modal-dialog>
    </div>
</template>

<script>
    import EventLog from '../Widgets/EventLog'
    import ModalDialog from '../Widgets/ModalDialog'
    import TransactionLog from '../Widgets/TransactionLog'
    import ReceiptList from '../Widgets/ReceiptList'
    import { ListTable } from '../Widgets/ListTools'
    import { default as DistlistList } from '../Distlist/ListWidget'
    import { default as DomainList } from '../Domain/ListWidget'
    import { default as ResourceList } from '../Resource/ListWidget'
    import { default as SharedFolderList } from '../SharedFolder/ListWidget'
    import { default as UserList } from '../User/ListWidget'

    import { library } from '@fortawesome/fontawesome-svg-core'

    library.add(
        require('@fortawesome/free-solid-svg-icons/faFolderOpen').definition,
        require('@fortawesome/free-solid-svg-icons/faGear').definition,
        require('@fortawesome/free-solid-svg-icons/faGlobe').definition,
        require('@fortawesome/free-solid-svg-icons/faUsers').definition,
    )

    export default {
        components: {
            DistlistList,
            DomainList,
            EventLog,
            ListTable,
            ModalDialog,
            ResourceList,
            SharedFolderList,
            TransactionLog,
            ReceiptList,
            UserList
        },
        beforeRouteUpdate (to, from, next) {
            // An event called when the route that renders this component has changed,
            // but this component is reused in the new route.
            // Required to handle links from /user/XXX to /user/YYY
            next()
            this.$parent.routerReload()
        },
        data() {
            return {
                aliasesListSetup: {
                    columns: [
                        {
                            prop: 'email',
                            content: item => item
                        },
                    ],
                    footLabel: 'user.aliases-none'
                },
                comment: '',
                discount: 0,
                discount_description: '',
                discounts: [],
                external_email: '',
                folders: [],
                has2FA: false,
                hasBeta: false,
                loadEventLog: false,
                oneoff_amount: '',
                oneoff_description: '',
                oneoff_negative: false,
                wallet: {},
                walletReload: false,
                distlists: [],
                domains: [],
                resources: [],
                sku2FA: null,
                skus: [],
                skusListSetup: {
                    columns: [
                        {
                            prop: 'name',
                            label: 'user.subscription'
                        },
                        {
                            prop: 'price',
                            className: 'price',
                            label: 'user.price'
                        }
                    ],
                    footLabel: 'user.subscriptions-none',
                    model: 'sku'
                },
                tabs: [
                    { label: 'user.finances' },
                    { label: 'wallet.receipts' },
                    { label: 'user.aliases', count: 0 },
                    { label: 'form.subscriptions', count: 0 },
                    { label: 'user.domains', count: 0 },
                    { label: 'user.users', count: 0 },
                    { label: 'user.distlists', count: 0 },
                    { label: 'user.resources', count: 0 },
                    { label: 'dashboard.shared-folders', count: 0 },
                    { label: 'form.settings' },
                    { label: 'log.history' }
                ],
                users: [],
                user: {
                    aliases: [],
                    config: {},
                    wallet: {},
                    settings: {},
                    skus: {},
                }
            }
        },
        computed: {
            suspendAction() {
                return this.user.isSuspended ? 'unsuspend' : 'suspend'
            }
        },
        created() {
            const user_id = this.$route.params.user

            axios.get('/api/v4/users/' + user_id, { loader: true })
                .then(response => {
                    this.user = response.data

                    const loader = '#finances'
                    const keys = ['first_name', 'last_name', 'external_email', 'billing_address', 'phone', 'organization']

                    let country = this.user.settings.country
                    if (country && country in window.config.countries) {
                        country = window.config.countries[country][1]
                    }

                    this.user.country = country

                    keys.forEach(key => { this.user[key] = this.user.settings[key] })

                    this.discount = this.user.wallet.discount
                    this.discount_description = this.user.wallet.discount_description

                    this.$refs.tabs.updateCounter('aliases', this.user.aliases.length)

                    // TODO: currencies, multi-wallets, accounts
                    // Get more info about the wallet (e.g. payment provider related)
                    axios.get('/api/v4/wallets/' + this.user.wallets[0].id, { loader })
                        .then(response => {
                            this.wallet = response.data
                            this.setMandateState()
                        })

                    // Create subscriptions list
                    axios.get('/api/v4/users/' + user_id + '/skus')
                        .then(response => {
                            // "merge" SKUs with user entitlement-SKUs
                            response.data.forEach(sku => {
                                const userSku = this.user.skus[sku.id]
                                if (userSku) {
                                    let cost = userSku.costs.reduce((sum, current) => sum + current)
                                    let item = {
                                        id: sku.id,
                                        name: sku.name,
                                        cost: cost,
                                        price: this.$root.priceLabel(cost, this.discount, this.user.wallet.currency)
                                    }

                                    if (sku.range) {
                                        item.name += ' ' + userSku.count + ' ' + sku.range.unit
                                    }

                                    this.skus.push(item)

                                    if (sku.handler == 'Auth2F') {
                                        this.has2FA = true
                                        this.sku2FA = sku.id
                                    } else if (sku.handler == 'Beta') {
                                        this.hasBeta = true
                                    }
                                }
                            })

                            this.$refs.tabs.updateCounter('subscriptions', this.skus.length)
                        })

                    // Fetch users
                    // TODO: Multiple wallets
                    axios.get('/api/v4/users?owner=' + user_id)
                        .then(response => {
                            this.users = response.data.list;
                            this.$refs.tabs.updateCounter('users', this.users.length)
                        })

                    // Fetch domains
                    axios.get('/api/v4/domains?owner=' + user_id)
                        .then(response => {
                            this.domains = response.data.list
                            this.$refs.tabs.updateCounter('domains', this.domains.length)
                        })

                    // Fetch distribution lists
                    axios.get('/api/v4/groups?owner=' + user_id)
                        .then(response => {
                            this.distlists = response.data.list
                            this.$refs.tabs.updateCounter('distlists', this.distlists.length)
                        })

                    // Fetch resources lists
                    axios.get('/api/v4/resources?owner=' + user_id)
                        .then(response => {
                            this.resources = response.data.list
                            this.$refs.tabs.updateCounter('resources', this.resources.length)
                        })

                    // Fetch shared folders lists
                    axios.get('/api/v4/shared-folders?owner=' + user_id)
                        .then(response => {
                            this.folders = response.data.list
                            this.$refs.tabs.updateCounter('folders', this.folders.length)
                        })
                })
                .catch(this.$root.errorHandler)
        },
        mounted() {
            this.$refs.tabs.clickHandler('history', () => { this.loadEventLog = true })
            this.$refs.discountDialog.events({
                shown: () => {
                    // Note: Vue v-model is strict, convert null to a string
                    this.wallet.discount_id = this.wallet.discount_id || ''
                }
            })
        },
        methods: {
            addBetaSku() {
                axios.post('/api/v4/users/' + this.user.id + '/skus/beta')
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.hasBeta = true
                            const sku = response.data.sku
                            this.skus.push({
                                id: sku.id,
                                name: sku.name,
                                cost: sku.cost,
                                price: this.$root.priceLabel(sku.cost, this.discount, this.wallet.currency)
                            })

                            this.$refs.tabs.updateCounter('subscriptions', this.skus.length)
                        }
                    })
            },
            capitalize(str) {
                return str.charAt(0).toUpperCase() + str.slice(1)
            },
            awardDialog() {
                this.oneOffDialog(false)
            },
            discountEdit() {
                this.$refs.discountDialog.show()

                if (!this.discounts.length) {
                    // Fetch discounts
                    axios.get('/api/v4/users/' + this.user.id + '/discounts')
                        .then(response => {
                            this.discounts = response.data.list
                        })
                }
            },
            emailEdit() {
                this.external_email = this.user.external_email
                this.$root.clearFormValidation($('#email-dialog'))
                this.$refs.emailDialog.show()
            },
            setMandateState() {
                let mandate = this.wallet.mandate
                if (mandate && mandate.id) {
                    if (!mandate.isValid) {
                        this.wallet.mandateState = mandate.isPending ? 'pending' : 'invalid'
                    } else if (mandate.isDisabled) {
                        this.wallet.mandateState = 'disabled'
                    }
                }
            },
            oneOffDialog(negative) {
                this.oneoff_negative = negative
                this.$refs.oneoffDialog.show()
            },
            penalizeDialog() {
                this.oneOffDialog(true)
            },
            reload() {
                // this is to reload transaction log
                this.walletReload = true
                this.$nextTick(() => { this.walletReload = false })
            },
            reset2FA() {
                this.$refs.reset2faDialog.hide()
                axios.post('/api/v4/users/' + this.user.id + '/reset-2fa')
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.skus = this.skus.filter(sku => sku.id != this.sku2FA)
                            this.has2FA = false
                            this.$refs.tabs.updateCounter('subscriptions', this.skus.length)
                        }
                    })
            },
            resetGeoLock() {
                axios.post('/api/v4/users/' + this.user.id + '/reset-geolock')
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.user.config.limit_geo = []
                        }
                    })
            },
            resyncUser() {
                axios.post('/api/v4/users/' + this.user.id + '/resync')
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                        }
                    })
            },
            submitDiscount() {
                this.$refs.discountDialog.hide()

                axios.put('/api/v4/wallets/' + this.user.wallets[0].id, { discount: this.wallet.discount_id })
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.wallet = Object.assign({}, this.wallet, response.data)

                            // Update prices in Subscriptions tab
                            if (this.user.wallet.id == response.data.id) {
                                this.discount = this.wallet.discount
                                this.discount_description = this.wallet.discount_description

                                this.skus.forEach(sku => {
                                    sku.price = this.$root.priceLabel(sku.cost, this.discount, this.wallet.currency)
                                })
                            }
                        }
                    })
            },
            submitEmail() {
                axios.put('/api/v4/users/' + this.user.id, { external_email: this.external_email })
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$refs.emailDialog.hide()
                            this.$toast.success(response.data.message)
                            this.user.external_email = this.external_email
                            this.external_email = null // required because of Vue
                        }
                    })
            },
            submitOneOff() {
                let wallet_id = this.user.wallets[0].id
                let post = {
                    amount: this.oneoff_amount,
                    description: this.oneoff_description
                }

                if (this.oneoff_negative && /^\d+(\.?\d+)?$/.test(post.amount)) {
                    post.amount *= -1
                }

                this.$root.clearFormValidation('#oneoff-dialog')

                axios.post('/api/v4/wallets/' + wallet_id + '/one-off', post)
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$refs.oneoffDialog.hide()
                            this.$toast.success(response.data.message)
                            this.wallet = Object.assign({}, this.wallet, {balance: response.data.balance})
                            this.oneoff_amount = ''
                            this.oneoff_description = ''
                            this.reload()
                        }
                    })
            },
            setSuspendState() {
                this.$root.clearFormValidation($('#suspend-dialog'))
                this.$refs.suspendDialog.show()
            },
            submitSuspend() {
                const post = { comment: this.comment }

                axios.post(`/api/v4/users/${this.user.id}/${this.suspendAction}`, post)
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.user = Object.assign({}, this.user, { isSuspended: !this.user.isSuspended })
                            this.$refs.suspendDialog.hide()
                            this.comment = ''

                            if (this.loadEventLog) {
                                this.$refs.eventLog.loadLog({ reset: true })
                            }
                        }
                    })
            }
        }
    }
</script>
