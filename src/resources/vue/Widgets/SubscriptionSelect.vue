<template>
    <div>
        <table class="table table-sm form-list">
            <thead class="visually-hidden">
                <tr>
                    <th scope="col"></th>
                    <th scope="col">{{ $t('user.subscription') }}</th>
                    <th scope="col">{{ $t('user.price') }}</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="sku in skus" :id="'s' + sku.id" :key="sku.id">
                    <td class="selection">
                        <input type="checkbox" @input="onInputSku"
                               :value="sku.id"
                               :disabled="sku.readonly || readonly"
                               :checked="sku.enabled"
                               :id="'sku-input-' + sku.title"
                        >
                    </td>
                    <td class="name">
                        <label :for="'sku-input-' + sku.title">{{ sku.name }}</label>
                        <div v-if="sku.range" class="range-input">
                            <label class="text-nowrap">{{ sku.range.min }} {{ sku.range.unit }}</label>
                            <input type="range" class="form-range" @input="rangeUpdate"
                                   :value="sku.value || sku.range.min"
                                   :min="sku.range.min"
                                   :max="sku.range.max"
                            >
                        </div>
                    </td>
                    <td class="price text-nowrap">
                        {{ $root.priceLabel(sku.cost, discount, currency) }}
                    </td>
                    <td class="buttons">
                        <btn v-if="sku.description" class="btn-link btn-lg p-0" v-tooltip="sku.description" icon="circle-info">
                            <span class="visually-hidden">{{ $t('btn.moreinfo') }}</span>
                        </btn>
                    </td>
                </tr>
            </tbody>
        </table>
        <small v-if="discount > 0" class="hint">
            <hr class="m-0 mt-1">
            &sup1; {{ $t('user.discount-hint') }}: {{ discount }}% - {{ discount_description }}
        </small>
    </div>
</template>

<script>
    export default {
        props: {
            object: { type: Object, default: () => {} },
            readonly: { type: Boolean, default: false },
            type: { type: String, default: 'user' }
        },
        data() {
            return {
                currency: '',
                discount: 0,
                discount_description: '',
                skus: []
            }
        },
        created() {
            // assign currency, discount, discount_description of the current user
            this.$root.userWalletProps(this)

            if (this.object.wallet) {
                this.discount = this.object.wallet.discount
                this.discount_description = this.object.wallet.discount_description
            }

            let url = '/api/v4/' + this.type + 's/' + this.object.id + '/skus'

            if (!this.object.id) {
                url = '/api/v4/skus?type=' + this.type.replace(/-([a-z])/g, (match, p1) => p1.toUpperCase())
            }

            axios.get(url, { loader: true })
                .then(response => {
                    if (this.readonly && this.object.skus) {
                        response.data.list = response.data.list.filter(sku => { return sku.id in this.object.skus })
                    }

                    // "merge" SKUs with user entitlement-SKUs
                    this.skus = response.data.list
                        .map(sku => {
                            const objSku = this.object.skus ? this.object.skus[sku.id] : null
                            if (objSku) {
                                sku.enabled = true
                                sku.skuCost = sku.cost
                                sku.cost = objSku.costs.reduce((sum, current) => sum + current)
                                sku.value = objSku.count
                                sku.costs = objSku.costs
                            } else {
                                if ('nextCost' in sku) {
                                    sku.cost = sku.nextCost
                                }

                                if (!sku.readonly && this.object.skus) {
                                    sku.enabled = false
                                }
                            }

                            return sku
                        })

                    this.$nextTick(() => {
                        // Update all range inputs (and price)
                        $(this.$el).find('input[type=range]').each((idx, elem) => { this.rangeUpdate(elem) })

                        // Mark 'exclusive' SKUs as readonly, they can't be unchecked
                        this.skus.forEach(item => {
                            if (item.exclusive.length && item.enabled) {
                                $('#s' + item.id).find('input[type=checkbox]')[0].readOnly = true
                            }
                        })
                    })
                })
                .catch(this.$root.errorHandler)
        },
        methods: {
            findSku(id) {
                for (let i = 0; i < this.skus.length; i++) {
                    if (this.skus[i].id == id) {
                        return this.skus[i];
                    }
                }
            },
            onInputSku(e) {
                let input = e.target
                let sku = this.findSku(input.value)
                let required = []

                // We use 'readonly', not 'disabled', because we might want to handle
                // input events. For example to display an error when someone clicks
                // the locked input
                if (input.readOnly) {
                    input.checked = !input.checked
                    // TODO: Display an alert explaining why it's locked
                    return
                }

                // TODO: Following code might not work if we change definition of forbidden/required
                //       or we just need more sophisticated SKU dependency rules

                if (input.checked) {
                    // Check if a required SKU is selected, alert the user if not
                    sku.required.forEach(handler => {
                        this.skus.forEach(item => {
                            if (item.handler == handler) {
                                if (!$('#s' + item.id).find('input[type=checkbox]:checked').length) {
                                    required.push(item.name)
                                }
                            }
                        })
                    })

                    if (required.length) {
                        input.checked = false
                        return alert(this.$t('user.skureq', { sku: sku.name, list: required.join(', ') }))
                    }

                    // Make sure there must be only one of 'exclusive' SKUs
                    if (sku.exclusive.length) {
                        input.readOnly = true

                        this.skus.forEach(item => {
                            if (sku.exclusive.includes(item.handler)) {
                                $('#s' + item.id).find('input[type=checkbox]').prop({
                                        checked: false,
                                        readonly: false
                                })
                            }
                        })
                    }
                } else {
                    // Uncheck all dependent SKUs, e.g. when unchecking Groupware we also uncheck Activesync
                    // TODO: Should we display an alert instead?
                    this.skus.forEach(item => {
                        if (item.required && item.required.indexOf(sku.handler) > -1) {
                            $('#s' + item.id).find('input[type=checkbox]').prop('checked', false)
                        }
                    })
                }

                // Uncheck+lock/unlock conflicting SKUs
                sku.forbidden.forEach(handler => {
                    this.skus.forEach(item => {
                        let checkbox
                        if (item.handler == handler && (checkbox = $('#s' + item.id).find('input[type=checkbox]')[0])) {
                            if (input.checked) {
                                checkbox.checked = false
                                checkbox.readOnly = true
                            } else {
                                checkbox.readOnly = false
                            }
                        }
                    })
                })
            },
            rangeUpdate(e) {
                let input = $(e.target || e)
                let value = input.val()
                let record = input.parents('tr').first()
                let sku_id = record.find('input[type=checkbox]').val()
                let sku = this.findSku(sku_id)
                let existing = sku.costs ? sku.costs.length : 0
                let cost

                // Calculate cost, considering both existing entitlement cost and sku cost
                if (existing) {
                    cost = sku.costs
                        .sort((a, b) => a - b) // sort by cost ascending (free units first)
                        .slice(0, value)
                        .reduce((sum, current) => sum + current)

                    if (value > existing) {
                        cost += sku.skuCost * (value - existing)
                    }
                } else {
                    cost = sku.cost * (value - sku.units_free)
                }

                // Update the label
                input.prev().text(value + ' ' + sku.range.unit)

                // Update the price
                record.find('.price').text(this.$root.priceLabel(cost, this.discount, this.currency))
            },
            getSkus() {
                let skus = {}

                $(this.$el).find('input[type=checkbox]:checked').each((idx, input) => {
                    let id = $(input).val()
                    let range = $(input).parents('tr').first().find('input[type=range]').val()

                    skus[id] = range || 1
                })

                return skus
            }
        }
    }
</script>
