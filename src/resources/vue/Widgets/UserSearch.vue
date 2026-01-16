<template>
    <div id="search-box" class="card">
        <div class="card-body">
            <form @submit.prevent="searchUser" class="row justify-content-center">
                <div class="input-group col-sm-8">
                    <input class="form-control" type="text" :placeholder="$t('user.search-pl')" v-model="search">
                    <btn type="submit" class="btn-primary" icon="magnifying-glass">{{ $t('btn.search') }}</btn>
                </div>
            </form>
            <table v-if="users.length" class="table table-sm table-hover mt-4">
                <thead>
                    <tr>
                        <th scope="col">{{ $t('form.primary-email') }}</th>
                        <th scope="col">{{ $t('form.id') }}</th>
                        <th scope="col" class="d-none d-md-table-cell">{{ $t('form.created') }}</th>
                        <th scope="col" class="d-none d-md-table-cell">{{ $t('form.deleted') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- eslint-disable vue/no-template-shadow -->
                    <tr v-for="user in users" :id="'user' + user.id" :key="user.id" :class="user.isDeleted ? 'text-secondary' : ''">
                        <td class="text-nowrap">
                            <svg-icon icon="user" :class="'me-1 ' + $root.statusClass(user)" :title="$root.statusText(user)"></svg-icon>
                            <router-link v-if="!user.isDeleted" :to="{ path: 'user/' + user.id }">{{ user.email }}</router-link>
                            <a v-if="user.isDeleted" href="#" @click="summaryDialog(user.id)">{{ user.email }}</a>
                        </td>
                        <td>
                            <router-link v-if="!user.isDeleted" :to="{ path: 'user/' + user.id }">{{ user.id }}</router-link>
                            <a v-if="user.isDeleted" href="#" @click="summaryDialog(user.id)">{{ user.id }}</a>
                        </td>
                        <td class="d-none d-md-table-cell">{{ toDate(user.created_at) }}</td>
                        <td class="d-none d-md-table-cell">{{ toDate(user.deleted_at) }}</td>
                    </tr>
                    <!-- eslint-enable -->
                </tbody>
            </table>
        </div>

        <modal-dialog id="summary-dialog" ref="summaryDialog" :title="$t('user.summary')">
            <form class="read-only short" style="min-height: 5em">
                <div class="row plaintext" v-if="user.email">
                    <label for="email" class="col-sm-4 col-form-label">{{ $t('form.email') }}</label>
                    <div class="col-sm-8">
                        <strong class="form-control-plaintext" id="email">{{ user.email }}</strong>
                    </div>
                </div>
                <div class="row plaintext" v-if="user.status">
                    <label for="status" class="col-sm-4 col-form-label">{{ $t('form.status') }}</label>
                    <div class="col-sm-8">
                        <span class="form-control-plaintext" id="status">
                            <span :class="$root.statusClass(user)">{{ $root.statusText(user) }}</span>
                            <span v-if="user.isRestricted" class="badge bg-primary rounded-pill ms-1">{{ $t('status.restricted') }}</span>
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
                <div class="row plaintext" v-if="user.external_email">
                    <label for="external_email" class="col-sm-4 col-form-label">{{ $t('user.ext-email') }}</label>
                    <div class="col-sm-8">
                        <span class="form-control-plaintext" id="external_email">
                            <a v-if="user.external_email" :href="'mailto:' + user.external_email">{{ user.external_email }}</a>
                        </span>
                    </div>
                </div>
                <div class="row plaintext" v-if="user.billing_address">
                    <label for="billing_address" class="col-sm-4 col-form-label">{{ $t('user.address') }}</label>
                    <div class="col-sm-8">
                        <span class="form-control-plaintext" style="white-space:pre" id="billing_address">{{ user.billing_address }}</span>
                    </div>
                </div>
                <div class="row plaintext" v-if="user.country">
                    <label for="country" class="col-sm-4 col-form-label">{{ $t('user.country') }}</label>
                    <div class="col-sm-8">
                        <span class="form-control-plaintext" id="country">{{ user.country }}</span>
                    </div>
                </div>
                <div class="row" v-if="user.providerLink">
                    <label class="col-sm-4 col-form-label">{{ capitalize(user.provider) }} {{ $t('form.id') }}</label>
                    <div class="col-sm-8">
                        <span class="form-control-plaintext" v-html="user.providerLink"></span>
                    </div>
                </div>
                <div class="row" v-if="user.wallet && user.wallet.user_id == user.id">
                    <label class="col-sm-4 col-form-label">{{ $t('wallet.balance') }}</label>
                    <div class="col-sm-8">
                        <span :class="(user.wallet.balance < 0 ? 'text-danger' : 'text-success') + ' form-control-plaintext'">
                            {{ $root.price(user.wallet.balance, user.wallet.currency) }}
                        </span>
                        <span v-if="user.wallet.discount">
                            ({{ $t('user.discount') }}: {{ user.wallet.discount + '% - ' + user.wallet.discount_description }})
                        </span>
                    </div>
                </div>
            </form>
        </modal-dialog>
    </div>
</template>

<script>
    import ModalDialog from '../Widgets/ModalDialog'

    export default {
        components: {
            ModalDialog
        },
        data() {
            return {
                search: '',
                user: {},
                users: []
            }
        },
        mounted() {
            $('#search-box input', this.$el).focus()
        },
        methods: {
            capitalize(str) {
                return str.charAt(0).toUpperCase() + str.slice(1)
            },
            searchUser() {
                this.users = []

                axios.get('/api/v4/users', { params: { search: this.search } })
                    .then(response => {
                        if (response.data.count == 1 && !response.data.list[0].isDeleted) {
                            this.$router.push({ name: 'user', params: { user: response.data.list[0].id } })
                            return
                        }

                        if (response.data.message) {
                            this.$toast.info(response.data.message)
                        }

                        this.users = response.data.list
                    })
                    .catch(this.$root.errorHandler)
            },
            summaryDialog(user_id) {
                this.user = {}
                this.$refs.summaryDialog.show()

                axios.get('/api/v4/users/' + user_id + '/summary', { loader: '#summary-dialog form' } )
                    .then(response => {
                        this.user = response.data

                        for (const key in this.user.settings) {
                            this.user[key] = this.user.settings[key]
                        }

                        const country = this.user.country
                        if (country && country in window.config.countries) {
                            this.user.country = window.config.countries[country][1]
                        }
                    });
            },
            toDate(datetime) {
                if (datetime) {
                    return datetime.split(' ')[0]
                }
            }
        }
    }
</script>
