<template>
    <div>
        <table class="table table-sm form-list">
            <thead class="visually-hidden">
                <tr>
                    <th scope="col"></th>
                    <th scope="col">{{ $t('user.package') }}</th>
                    <th scope="col">{{ $t('user.price') }}</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="pkg in packages" :id="'p' + pkg.id" :key="pkg.id">
                    <td class="selection">
                        <input type="checkbox" @change="selectPackage"
                               :value="pkg.id"
                               :checked="pkg.id == package_id"
                               :readonly="pkg.id == package_id"
                               :id="'pkg-input-' + pkg.id"
                        >
                    </td>
                    <td class="name">
                        <label :for="'pkg-input-' + pkg.id">{{ pkg.name }}</label>
                    </td>
                    <td class="price text-nowrap">
                        {{ $root.priceLabel(pkg.cost, discount, currency) }}
                    </td>
                    <td class="buttons">
                        <btn v-if="pkg.description" class="btn-link btn-lg p-0" v-tooltip="pkg.description" icon="circle-info">
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
            type: { type: String, default: 'user' }
        },
        data() {
            return {
                currency: '',
                discount: 0,
                discount_description: '',
                packages: [],
                package_id: null
            }
        },
        created() {
            // assign currency, discount, discount_description of the current user
            this.$root.userWalletProps(this)

            axios.get('/api/v4/packages', { loader: true })
                .then(response => {
                    this.packages = response.data.list.filter(pkg => {
                        if (this.type == 'domain') {
                            return pkg.isDomain
                        }

                        return !pkg.isDomain
                    })
                    this.package_id = this.packages[0].id
                })
                .catch(this.$root.errorHandler)
        },
        methods: {
            selectPackage(e) {
                // Make sure there always is one package selected
                $(this.$el).find('input').not(e.target).prop('checked', false)
                this.package_id = $(e.target).prop('checked', true).val()
            },
        }
    }
</script>
