<template>
    <div class="container">
        <status-component v-if="list_id !== 'new'" :status="status" @status-update="statusUpdate"></status-component>

        <div class="card" id="distlist-info">
            <div class="card-body">
                <div class="card-title" v-if="list_id !== 'new'">
                    {{ $tc('distlist.list-title', 1) }}
                    <btn class="btn-outline-danger button-delete float-end" @click="deleteList()" icon="trash-can">{{ $t('distlist.delete') }}</btn>
                </div>
                <div class="card-title" v-else>{{ $t('distlist.new') }}</div>
                <div class="card-text">
                    <tabs class="mt-3" :tabs="list_id === 'new' ? ['form.general'] : ['form.general','form.settings']"></tabs>
                    <div class="tab-content">
                        <div class="tab-pane show active" id="general" role="tabpanel" aria-labelledby="tab-general">
                            <form @submit.prevent="submit" class="card-body">
                                <div v-if="list_id !== 'new'" class="row plaintext mb-3">
                                    <label for="status" class="col-sm-4 col-form-label">{{ $t('form.status') }}</label>
                                    <div class="col-sm-8">
                                        <span :class="$root.statusClass(list) + ' form-control-plaintext'" id="status">{{ $root.statusText(list) }}</span>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <label for="name" class="col-sm-4 col-form-label">{{ $t('distlist.name') }}</label>
                                    <div class="col-sm-8">
                                        <input type="text" class="form-control" id="name" required v-model="list.name">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <label for="email" class="col-sm-4 col-form-label">{{ $t('form.email') }}</label>
                                    <div class="col-sm-8">
                                        <input type="text" class="form-control" id="email" :disabled="list_id !== 'new'" required v-model="list.email">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <label for="members-input" class="col-sm-4 col-form-label">{{ $t('distlist.recipients') }}</label>
                                    <div class="col-sm-8">
                                        <list-input id="members" :list="list.members"></list-input>
                                    </div>
                                </div>
                                <div v-if="$root.hasPermission('subscriptions') && (list_id === 'new' || list.id)" id="distlist-skus" class="row mb-3">
                                    <label class="col-sm-4 col-form-label">{{ $t('form.subscriptions') }}</label>
                                    <subscription-select class="col-sm-8 pt-sm-1" ref="skus" :object="list" type="group" :readonly="true"></subscription-select>
                                </div>
                                <btn class="btn-primary" type="submit" icon="check">{{ $t('btn.submit') }}</btn>
                            </form>
                        </div>
                        <div class="tab-pane" id="settings" role="tabpanel" aria-labelledby="tab-settings">
                            <form @submit.prevent="submitSettings" class="card-body">
                                <div class="row mb-3">
                                    <label for="sender-policy-input" class="col-sm-4 col-form-label">{{ $t('distlist.sender-policy') }}</label>
                                    <div class="col-sm-8 pt-2">
                                        <list-input id="sender-policy" :list="list.config.sender_policy" class="mb-1"></list-input>
                                        <small id="sender-policy-hint" class="text-muted">
                                            {{ $t('distlist.sender-policy-text') }}
                                        </small>
                                    </div>
                                </div>
                                <btn class="btn-primary" type="submit" icon="check">{{ $t('btn.submit') }}</btn>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
    import ListInput from '../Widgets/ListInput'
    import StatusComponent from '../Widgets/Status'
    import SubscriptionSelect from '../Widgets/SubscriptionSelect'
    import { userAutocomplete } from '../../js/utils'

    export default {
        components: {
            ListInput,
            StatusComponent,
            SubscriptionSelect
        },
        data() {
            return {
                list_id: null,
                list: { members: [], config: {} },
                status: {}
            }
        },
        created() {
            this.list_id = this.$route.params.list

            if (this.list_id != 'new') {
                axios.get('/api/v4/groups/' + this.list_id, { loader: true })
                    .then(response => {
                        this.list = response.data
                        this.status = response.data.statusInfo

                        this.$nextTick().then(() => { userAutocomplete($('#sender-policy-input').get(0)) })
                    })
                    .catch(this.$root.errorHandler)
            }
        },
        mounted() {
            $('#name').focus()
            userAutocomplete($('#members-input').get(0))
        },
        methods: {
            deleteList() {
                axios.delete('/api/v4/groups/' + this.list_id)
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.$router.push({ name: 'distlists' })
                        }
                    })
            },
            statusUpdate(list) {
                this.list = Object.assign({}, this.list, list)
            },
            submit() {
                this.$root.clearFormValidation($('#list-info form'))

                let method = 'post'
                let location = '/api/v4/groups'
                let post = this.$root.pick(this.list, ['name', 'email', 'members'])

                if (this.list_id !== 'new') {
                    method = 'put'
                    location += '/' + this.list_id
                }

                // post.skus = this.$refs.skus.getSkus()

                axios[method](location, post)
                    .then(response => {
                        this.$toast.success(response.data.message)
                        this.$router.push({ name: 'distlists' })
                    })
            },
            submitSettings() {
                this.$root.clearFormValidation($('#settings form'))

                const post = this.$root.pick(this.list.config, [ 'sender_policy' ])

                axios.post('/api/v4/groups/' + this.list_id + '/config', post)
                    .then(response => {
                        this.$toast.success(response.data.message)
                    })
            }
        }
    }
</script>
