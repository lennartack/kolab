<template>
    <div class="list-input acl-input" :id="id">
        <div class="input-group">
            <select v-if="!useronly" class="form-select mod mod-user" @change="changeMod" v-model="mod">
                <option value="user">{{ $t('form.user') }}</option>
                <option value="anyone">{{ $t('form.anyone') }}</option>
            </select>
            <input :id="id + '-input'" type="text" class="form-control main-input" :placeholder="$t('form.email')" @keydown="keyDown">
            <select v-if="types.length > 1" class="form-select acl" v-model="perm">
                <option v-for="t in types" :key="t" :value="t">{{ $t('form.acl-' + t) }}</option>
            </select>
            <a href="#" class="btn btn-outline-secondary" @click.prevent="addItem">
                <svg-icon icon="plus"></svg-icon><span class="visually-hidden">{{ $t('btn.add') }}</span>
            </a>
        </div>
        <div class="input-group" v-for="(item, index) in list" :key="index">
            <input type="text" class="form-control" :value="aclIdent(item)" :readonly="aclIdent(item) == 'anyone'" :placeholder="$t('form.email')">
            <select v-if="types.length > 1" class="form-select acl">
                <option v-for="t in types" :key="t" :value="t" :selected="aclPerm(item) == t">{{ $t('form.acl-' + t) }}</option>
            </select>
            <a href="#" class="btn btn-outline-secondary" @click.prevent="deleteItem(index)">
                <svg-icon icon="trash-can"></svg-icon><span class="visually-hidden">{{ $t('btn.delete') }}</span>
            </a>
        </div>
    </div>
</template>

<script>
    import { userAutocomplete } from '../../js/utils'

    const DEFAULT_TYPES = [ 'read-only', 'read-write', 'full' ]

    export default {
        props: {
            list: { type: Array, default: () => [] },
            id: { type: String, default: '' },
            types: { type: Array, default: () => DEFAULT_TYPES },
            useronly: { type: Boolean, default: false }
        },
        data() {
            return {
                mod: 'user',
                perm: 'read-only',
            }
        },
        mounted() {
            this.input = $(this.$el).find('.main-input')[0]
            this.select = $(this.$el).find('select')[0]

            // On form submit add the text from main input to the list
            // Users tend to forget about pressing the "plus" button
            // Note: We can't use form.onsubmit (too late)
            // Note: Use of input.onblur has been proven to be problematic
            // TODO: What with forms that have no submit button?
            $(this.$el).closest('form').find('button[type=submit]').on('click', () => {
                this.updateList()
                this.addItem(false)
            })

            userAutocomplete(this.input)
        },
        methods: {
            aclIdent(item) {
                return item.split(/\s*,\s*/)[0]
            },
            aclPerm(item) {
                return item.split(/\s*,\s*/)[1]
            },
            addItem(focus) {
                let value = this.input.value

                if (value || this.mod == 'anyone') {
                    if (this.mod == 'anyone') {
                        value = 'anyone'
                    }

                    const perm = this.types.length > 1 ? this.perm : this.types[0]

                    this.$set(this.list, this.list.length, value + ', ' + perm)

                    this.input.classList.remove('is-invalid')
                    this.input.value = ''
                    this.mod = 'user'
                    this.perm = 'read-only'
                    this.changeMod()

                    if (focus !== false) {
                        this.input.focus()
                    }

                    if (this.list.length == 1) {
                        this.$el.classList.remove('is-invalid')
                    }

                    this.$emit('change', this.$el)
                }
            },
            changeMod() {
                $(this.input)[this.mod == 'user' ? 'removeClass' : 'addClass']('d-none')
                $(this.input).prev()[this.mod == 'user' ? 'addClass' : 'removeClass']('mod-user')
            },
            deleteItem(index) {
                this.updateList()
                this.$delete(this.list, index)
                this.$emit('change', this.$el)

                if (!this.list.length) {
                    this.$el.classList.remove('is-invalid')
                }
            },
            keyDown(e) {
                if (e.which == 13 && e.target.value) {
                    this.addItem()
                    e.preventDefault()
                }
            },
            updateList() {
                // Update this.list to the current state of the html elements
                $(this.$el).children('.input-group:not(:first-child)').each((index, elem) => {
                    const perm = this.types.length > 1 ? $(elem).find('select.acl').val() : this.types[0]
                    const value = $(elem).find('input').val()
                    this.$set(this.list, index, value + ', ' + perm)
                })
            }
        }
    }
</script>
