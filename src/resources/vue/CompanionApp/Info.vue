<template>
    <div class="container">
        <div class="card">
            <div class="card-body">
                <div class="card-title">{{ $t('form.companion') }}
                    <btn class="btn-outline-danger button-delete float-end" @click="$refs.deleteDialog.show()" icon="trash-can">{{ $t('companion.delete') }}</btn>
                </div>
                <div class="card-text">
                    <form @submit.prevent="submit" class="card-body">
                        <div class="row mb-3">
                            <label for="name" class="col-sm-4 col-form-label">{{ $t('companion.name') }}</label>
                            <div class="col-sm-8">
                                <input type="text" class="form-control" id="name" v-model="companion.name" :disabled="companion.id">
                            </div>
                        </div>
                        <btn v-if="!companion.id" class="btn-primary mt-3" type="submit" icon="check">{{ $t('btn.submit') }}</btn>
                    </form>
                    <hr class="m-0" v-if="companion.id">
                    <div v-if="companion.id && !companion.isPaired" class="card-body" id="companion-verify">
                        <btn class="btn-outline-primary float-end" @click="printQRCode()" icon="print">{{ $t('companion.print') }}</btn>
                        <div class="card-text">
                            <p>
                                {{ $t('companion.pairing-instructions') }}
                            </p>
                            <p>
                                <img :src="qrcode" />
                            </p>
                        </div>
                    </div>
                    <div v-if="companion.isPaired" class="card-body" id="companion-config">
                        <div class="card-text">
                            <p>{{ $t('companion.pairing-successful', { app: $root.appName }) }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <modal-dialog id="delete-warning" ref="deleteDialog" @click="deleteCompanion()" :buttons="['delete']" :cancel-focus="true"
                      :title="$t('companion.delete-companion', { companion: companion.name })"
        >
            <p>{{ $t('companion.delete-text') }}</p>
        </modal-dialog>
    </div>
</template>

<script>
    import ListInput from '../Widgets/ListInput'
    import ModalDialog from '../Widgets/ModalDialog'
    import SubscriptionSelect from '../Widgets/SubscriptionSelect'

    import { library } from '@fortawesome/fontawesome-svg-core'

    library.add(
        require('@fortawesome/free-solid-svg-icons/faPrint').definition,
        require('@fortawesome/free-solid-svg-icons/faRotate').definition
    )

    export default {
        components: {
            ListInput,
            ModalDialog,
            SubscriptionSelect
        },
        beforeRouteUpdate (to, from, next) {
            // An event called when the route that renders this component has changed,
            // but this component is reused in the new route.
            // Required to handle links from /companion/XXX to /companion/YYY
            next()
            this.$parent.routerReload()
        },
        data() {
            return {
                companion_id: null,
                companion: {},
                qrcode: "",
            }
        },
        created() {
            this.companion_id = this.$route.params.companion

            axios.get('/api/v4/companions/' + this.companion_id, { loader: true })
                .then(response => {
                    this.companion = response.data
                })
                .catch(this.$root.errorHandler)

            axios.get('/api/v4/companions/' + this.companion_id + '/pairing/', { loader: true })
                .then(response => {
                    this.qrcode = response.data.qrcode
                })
                .catch(this.$root.errorHandler)
        },
        methods: {
            printQRCode() {
                window.print();
            },
            deleteCompanion() {
                axios.delete('/api/v4/companions/' + this.companion_id)
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.$router.push({ name: 'companions' })
                        }
                    })
            },
            submit() {
                this.$root.clearFormValidation($('#general form'))

                let post = this.$root.pick(this.companion, ['name'])

                axios.post('/api/v4/companions', post)
                    .then(response => {
                        this.$toast.success(response.data.message)
                        this.$router.replace({ name: 'companion' , params: { companion: response.data.id }})
                    })
            }
        }
    }
</script>
