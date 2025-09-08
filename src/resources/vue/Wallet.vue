<template>
    <div class="container" dusk="wallet-component">
        <div v-if="wallet.id" id="wallet" class="card">
            <div class="card-body">
                <div class="card-title">{{ $t('wallet.title') }} <span :class="wallet.balance < 0 ? 'text-danger' : 'text-success'">{{ $root.price(wallet.balance, wallet.currency) }}</span></div>
                <div class="card-text">
                    <p v-if="wallet.notice" id="wallet-notice">{{ wallet.notice }}</p>

                    <div v-if="showPendingPayments" class="alert alert-warning">
                        {{ $t('wallet.pending-payments-warning') }}
                    </div>
                    <p id="wallet-add-credit">
                        <btn class="btn-primary" @click="paymentMethodForm('manual')">{{ $t('wallet.add-credit') }}</btn>
                    </p>
                    <div id="mandate-form" v-if="!mandate.isValid && !mandate.isPending && $root.hasPermission('walletMandates')">
                        <template v-if="mandate.id && !mandate.isValid">
                            <div class="alert alert-danger">
                                {{ $t('wallet.auto-payment-failed') }}
                            </div>
                            <btn class="btn-danger" @click="autoPaymentDelete">{{ $t('wallet.auto-payment-cancel') }}</btn>
                        </template>
                        <btn class="btn-primary" @click="paymentMethodForm('auto')">{{ $t('wallet.auto-payment-setup') }}</btn>
                    </div>
                    <div id="mandate-info" v-else-if="$root.hasPermission('walletMandates')">
                        <div v-if="mandate.isDisabled" class="disabled-mandate alert alert-danger">
                            {{ $t('wallet.auto-payment-disabled') }}
                        </div>
                        <template v-else>
                            <p v-html="$t('wallet.auto-payment-info', { amount: mandate.amount + ' ' + wallet.currency, balance: mandate.balance + ' ' + wallet.currency})"></p>
                            <p>{{ $t('wallet.payment-method', { method: mandate.method }) }}</p>
                        </template>
                        <div v-if="mandate.isPending" class="alert alert-warning">
                            {{ $t('wallet.auto-payment-inprogress') }}
                        </div>
                        <p class="buttons">
                            <btn class="btn-danger" @click="autoPaymentDelete">{{ $t('wallet.auto-payment-cancel') }}</btn>
                            <btn class="btn-primary" @click="autoPaymentChange">{{ $t('wallet.auto-payment-change') }}</btn>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <tabs class="mt-3" ref="tabs" :tabs="tabs"></tabs>

        <div class="tab-content">
            <div class="tab-pane active" id="receipts" role="tabpanel" aria-labelledby="tab-receipts">
                <div class="card-body">
                    <receipt-list v-if="walletId && loadReceipts" class="card-text" :wallet-id="walletId"></receipt-list>
                </div>
            </div>
            <div class="tab-pane" id="history" role="tabpanel" aria-labelledby="tab-history">
                <div class="card-body">
                    <transaction-log v-if="walletId && loadTransactions" class="card-text" :wallet-id="walletId"></transaction-log>
                </div>
            </div>
            <div class="tab-pane" id="payments" role="tabpanel" aria-labelledby="tab-payments">
                <div class="card-body">
                    <payment-log v-if="walletId && loadPayments" class="card-text" :wallet-id="walletId"></payment-log>
                </div>
            </div>
            <div class="tab-pane" id="refprograms" role="tabpanel" aria-labelledby="tab-refprograms">
                <div class="card-body">
                    <referral-programs v-if="walletId && loadReferrals" class="card-text" :wallet-id="walletId"></referral-programs>
                </div>
            </div>
        </div>

        <modal-dialog id="payment-dialog" ref="paymentDialog" :title="paymentDialogTitle" @click="payment" :buttons="dialogButtons">
            <div id="payment-method" v-if="paymentForm == 'method'">
                <form data-validation-prefix="mandate_">
                    <div id="payment-method-selection">
                        <a v-for="method in paymentMethods" :key="method.id" @click="selectPaymentMethod(method)" href="#" :class="'card link-' + method.id">
                            <svg-icon v-if="method.icon" :icon="[method.icon.prefix, method.icon.name]" />
                            <img v-if="method.image" :src="method.image" />
                            <span class="name">{{ method.name }}</span>
                        </a>
                    </div>
                </form>
            </div>
            <div id="manual-payment" v-if="paymentForm == 'manual'">
                <p v-if="wallet.currency != selectedPaymentMethod.currency && selectedPaymentMethod.id != 'bitcoin'">
                    {{ $t('wallet.currency-conv', { wc: wallet.currency, pc: selectedPaymentMethod.currency }) }}
                </p>
                <p v-if="selectedPaymentMethod.id == 'bitcoin'">
                    {{ $t('wallet.coinbase-hint', { wc: wallet.currency }) }}
                </p>
                <p v-if="selectedPaymentMethod.id == 'banktransfer'">
                    {{ $t('wallet.banktransfer-hint') }}
                </p>
                <p>
                    {{ $t('wallet.payment-amount-hint') }}
                </p>
                <form id="payment-form" @submit.prevent="payment">
                    <div class="input-group">
                        <input type="text" class="form-control" id="amount" v-model="amount" required>
                        <span class="input-group-text">{{ wallet.currency }}</span>
                    </div>
                    <div v-if="wallet.currency != selectedPaymentMethod.currency && !isNaN(amount) && selectedPaymentMethod.exchangeRate" class="alert alert-warning m-0 mt-3">
                        {{ $t('wallet.payment-warning', { price: $root.price(amount * selectedPaymentMethod.exchangeRate * 100, selectedPaymentMethod.currency) }) }}
                    </div>
                </form>
                <div class="alert alert-warning m-0 mt-3">
                    {{ $t('wallet.norefund') }}
                </div>
            </div>
            <div id="auto-payment" v-if="paymentForm == 'auto'">
                <form data-validation-prefix="mandate_">
                    <p>
                        {{ $t('wallet.auto-payment-hint') }}
                    </p>
                    <div class="row mb-3">
                        <label for="mandate_amount" class="col-sm-6 col-form-label">{{ $t('wallet.fill-up') }}</label>
                        <div class="col-sm-6">
                            <div class="input-group">
                                <input type="text" class="form-control" id="mandate_amount" v-model="mandate.amount" required>
                                <span class="input-group-text">{{ wallet.currency }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label for="mandate_balance" class="col-sm-6 col-form-label">{{ $t('wallet.when-below') }}</label>
                        <div class="col-sm-6">
                            <div class="input-group">
                                <input type="text" class="form-control" id="mandate_balance" v-model="mandate.balance" required>
                                <span class="input-group-text">{{ wallet.currency }}</span>
                            </div>
                        </div>
                    </div>
                    <p v-if="!mandate.isValid">
                        {{ $t('wallet.auto-payment-next') }}
                    </p>
                    <div v-if="mandate.isValid && mandate.isDisabled" class="disabled-mandate alert alert-danger m-0">
                        {{ $t('wallet.auto-payment-disabled-next') }}
                    </div>
                </form>
                <div class="alert alert-warning m-0 mt-3">
                    {{ $t('wallet.norefund') }}
                </div>
            </div>
        </modal-dialog>
    </div>
</template>

<script>
    import ModalDialog from './Widgets/ModalDialog'
    import TransactionLog from './Widgets/TransactionLog'
    import PaymentLog from './Widgets/PaymentLog'
    import ReceiptList from './Widgets/ReceiptList'
    import ReferralPrograms from './Widgets/ReferralPrograms'
    import { paymentCheckout } from '../js/utils'

    import { library } from '@fortawesome/fontawesome-svg-core'

    library.add(
        require('@fortawesome/free-brands-svg-icons/faBitcoin').definition,
        require('@fortawesome/free-solid-svg-icons/faBuildingColumns').definition,
        require('@fortawesome/free-regular-svg-icons/faCreditCard').definition,
        require('@fortawesome/free-solid-svg-icons/faDownload').definition,
        require('@fortawesome/free-brands-svg-icons/faPaypal').definition
    )

    export default {
        components: {
            ModalDialog,
            TransactionLog,
            PaymentLog,
            ReceiptList,
            ReferralPrograms
        },
        data() {
            return {
                amount: '',
                mandate: { amount: 10, balance: 0, method: null },
                paymentDialogTitle: null,
                paymentForm: null,
                nextForm: null,
                receipts: [],
                loadTransactions: false,
                loadPayments: false,
                loadReceipts: true,
                loadReferrals: false,
                showPendingPayments: false,
                wallet: {},
                walletId: null,
                paymentMethods: [],
                selectedPaymentMethod: null
            }
        },
        computed: {
            dialogButtons() {
                if (this.paymentForm == 'method') {
                    return []
                }

                const button = {
                    className: 'btn-primary modal-action',
                    icon: 'check',
                    label: 'btn.submit'
                }

                if (this.paymentForm == 'manual'
                    || (this.paymentForm == 'auto' && !this.mandate.isValid && !this.mandate.isPending)
                ) {
                    button.label = 'btn.continue'
                }

                return [ button ]
            },
            tabs() {
                let tabs = [ 'wallet.receipts', 'wallet.history', 'wallet.refprograms' ]
                if (this.showPendingPayments) {
                    tabs.push('wallet.pending-payments')
                }
                return tabs
            }
        },
        mounted() {
            $('#wallet button').focus()

            this.walletId = this.$root.authInfo.wallets[0].id

            axios.get('/api/v4/wallets/' + this.walletId, { loader: true })
                .then(response => {
                    this.wallet = response.data

                    axios.get('/api/v4/wallets/' + this.walletId + '/receipts', { loader: '#receipts' })
                        .then(response => {
                            this.receipts = response.data.list
                        })
                })
                .catch(this.$root.errorHandler)

            this.loadMandate()

            axios.get('/api/v4/payments/has-pending')
                .then(response => {
                    this.showPendingPayments = response.data.hasPending
                })

            this.$refs.tabs.clickHandler('history', () => { this.loadTransactions = true })
            this.$refs.tabs.clickHandler('payments', () => { this.loadPayments = true })
            this.$refs.tabs.clickHandler('receipts', () => { this.loadReceipts = true })
            this.$refs.tabs.clickHandler('refprograms', () => { this.loadReferrals = true })
        },
        methods: {
            loadMandate() {
                const loader = '#mandate-form'

                this.$root.stopLoading(loader)

                axios.get('/api/v4/payments/mandate', { loader })
                    .then(response => {
                        this.mandate = response.data

                        if (this.mandate.minAmount) {
                            if (this.mandate.minAmount > this.mandate.amount) {
                                this.mandate.amount = this.mandate.minAmount
                            }
                        }
                    })
            },
            selectPaymentMethod(method) {
                this.formLock = false
                this.selectedPaymentMethod = method
                this.paymentForm = this.nextForm

                setTimeout(() => { $('#payment-dialog').find('#amount,#mandate_amount').focus() }, 10)
            },
            payment() {
                if (this.paymentForm == 'auto') {
                    return this.autoPayment()
                }

                if (this.formLock) {
                    return
                }

                // Lock the form to prevent from double submission
                this.formLock = true
                let onFinish = () => { this.formLock = false }

                this.$root.clearFormValidation($('#payment-form'))

                const post =  {
                    amount: this.amount,
                    methodId: this.selectedPaymentMethod.id,
                    currency: this.selectedPaymentMethod.currency
                }

                axios.post('/api/v4/payments', post, { onFinish })
                    .then(response => {
                        paymentCheckout(this, response.data)
                        this.$refs.paymentDialog.hide();
                    })
            },
            autoPayment() {
                if (this.formLock) {
                    return
                }

                // Lock the form to prevent from double submission
                this.formLock = true
                let onFinish = () => { this.formLock = false }

                const method = this.mandate.id && (this.mandate.isValid || this.mandate.isPending) ? 'put' : 'post'
                let post = {
                    amount: this.mandate.amount,
                    balance: this.mandate.balance,
                }

                // Modifications can't change the method of payment
                if (this.selectedPaymentMethod) {
                    post.methodId = this.selectedPaymentMethod.id
                    post.currency = this.selectedPaymentMethod.currency
                }

                this.$root.clearFormValidation($('#auto-payment form'))

                axios[method]('/api/v4/payments/mandate', post, { onFinish })
                    .then(response => {
                        if (method == 'post') {
                            this.mandate.id = null
                            // a new mandate, redirect to the chackout page
                            paymentCheckout(this, response.data)
                        } else {
                            // an update
                            if (response.data.status == 'success') {
                                this.$refs.paymentDialog.hide();
                                this.mandate = response.data.mandate
                                this.$toast.success(response.data.message)
                            }
                        }
                    })
            },
            autoPaymentChange(event) {
                this.autoPaymentForm(event, this.$t('wallet.auto-payment-update'))
            },
            autoPaymentDelete() {
                axios.delete('/api/v4/payments/mandate')
                    .then(response => {
                        this.mandate = { amount: 10, balance: 0 }
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                        }
                    })
            },
            paymentMethodForm(nextForm) {
                this.formLock = false
                this.paymentMethods = []
                this.paymentForm = 'method'
                this.nextForm = nextForm
                this.paymentDialogTitle = this.$t(nextForm == 'auto' ? 'wallet.auto-payment-setup' : 'wallet.top-up')

                this.$refs.paymentDialog.show()

                this.$nextTick().then(() => {
                    const type = nextForm == 'manual' ? 'oneoff' : 'recurring'
                    const loader = ['#payment-method', { 'min-height': '10em', small: false }]

                    axios.get('/api/v4/payments/methods', { params: { type }, loader })
                        .then(response => {
                            this.paymentMethods = response.data
                            if (this.paymentMethods.length == 1) {
                                this.nextForm = 'auto';
                                this.selectPaymentMethod(this.paymentMethods[0]);
                            }
                        })
                })
            },
            autoPaymentForm(event, title) {
                this.paymentForm = 'auto'
                this.paymentDialogTitle = title
                this.formLock = false

                this.$refs.paymentDialog.show()
            },
        }
    }
</script>
