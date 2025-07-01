<template>
    <div class="password-input">
        <div :id="prefix + 'password_input'">
            <input type="password"
                   class="form-control"
                   autocomplete="new-password"
                   :id="prefix + 'password'"
                   :placeholder="$t(placeholder ? placeholder : 'form.password')"
                   v-model="password"
                   @input="onInput"
            >
            <input type="password"
                   class="form-control mt-2"
                   autocomplete="new-password"
                   :id="prefix + 'password_confirmation'"
                   :placeholder="$t('form.password-confirm')"
                   v-model="password_confirmation"
                   @input="onInputConfirm"
            >
        </div>
        <ul v-if="policy.length" :id="prefix + 'password_policy'" class="list-group pt-2">
            <li v-for="rule in policy" :key="rule.label" class="list-group-item border-0 p-0">
                <svg-icon v-if="rule.status" icon="check" class="text-success"></svg-icon>
                <span v-else class="text-secondary">&bullet;</span>
                <small class="ps-1 form-text">{{ rule.name }}</small>
            </li>
        </ul>
    </div>
</template>

<script>
    export default {
        props: {
            focus: { type: Boolean, default: false },
            value: { type: Object, default: () => {} },
            placeholder: { type: String, default: '' },
            prefix: { type: String, default: '' },
            user: { type: [String, Number], default: '' }
        },
        data() {
            return {
                password: '',
                password_confirmation: '',
                policy: [],
            }
        },
        mounted() {
            this.checkPolicy('')

            const input = $('#password')[0]

            if (this.prefix == '') {
                this.prefix = $(input.form).data('validation-prefix') || ''
            }

            $(input.form).on('reset', () => { this.checkPolicy('') })

            if (this.focus) {
                input.focus()
            }
        },
        methods: {
            checkPolicy(password) {
                if (this.cancelToken) {
                    this.cancelToken.cancel()
                }

                const post = { password, user: this.user }

                if (!post.user && this.$root.authInfo) {
                    post.user = this.$root.authInfo.id
                }

                const cancelToken = axios.CancelToken;
                this.cancelToken = cancelToken.source();

                axios.post('/api/auth/password-policy-check', post, { cancelToken: this.cancelToken.token })
                    .then(response => {
                        if (response.data.list) {
                            this.policy = response.data.list
                        }
                    })
                    .catch(() => {})
            },
            onInput(event) {
                this.checkPolicy(event.target.value)
                this.update()
            },
            onInputConfirm(event) {
                this.update()
            },
            update() {
                const update = { password: this.password, password_confirmation: this.password_confirmation }
                this.$emit('input', {...this.value, ...update})
            }
        }
    }
</script>
