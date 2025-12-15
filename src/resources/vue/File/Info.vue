<template>
    <div class="container">
        <div class="card" id="file-info">
            <div class="card-body">
                <div class="card-title">
                    {{ file.name }}
                    <btn v-if="file.canDelete" class="btn-outline-danger button-delete float-end" @click="fileDelete" icon="trash-can">{{ $t('file.delete') }}</btn>
                </div>
                <div class="card-text">
                    <tabs class="mt-3" :tabs="file.isOwner ? ['form.general','file.sharing'] : ['form.general']"></tabs>
                    <div class="tab-content">
                        <form class="tab-pane show active card-body read-only short" id="general" role="tabpanel" aria-labelledby="tab-general">
                            <div class="row plaintext">
                                <label for="mimetype" class="col-sm-4 col-form-label">{{ $t('file.mimetype') }}</label>
                                <div class="col-sm-8">
                                    <span class="form-control-plaintext" id="mimetype">{{ file.mimetype }}</span>
                                </div>
                            </div>
                            <div class="row plaintext">
                                <label for="size" class="col-sm-4 col-form-label">{{ $t('form.size') }}</label>
                                <div class="col-sm-8">
                                    <span class="form-control-plaintext" id="size">{{ api.sizeText(file.size) }}</span>
                                </div>
                            </div>
                            <div class="row plaintext mb-3">
                                <label for="mtime" class="col-sm-4 col-form-label">{{ $t('file.mtime') }}</label>
                                <div class="col-sm-8">
                                    <span class="form-control-plaintext" id="mtime">{{ file.created_at }}</span>
                                </div>
                            </div>
                            <btn class="btn-primary" icon="download" @click="fileDownload">{{ $t('btn.download') }}</btn>
                        </form>
                        <div v-if="file.isOwner" class="tab-pane card-body" id="sharing" role="tabpanel" aria-labelledby="tab-sharing">
                            <div id="share-form" class="mb-3">
                                <div class="row">
                                    <small id="share-links-hint" class="text-muted mb-2">
                                        {{ $t('file.sharing-links-text') }}
                                    </small>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="user" :placeholder="$t('form.email')">
                                        <a href="#" class="btn btn-outline-secondary" @click.prevent="shareAdd">
                                            <svg-icon icon="plus"></svg-icon><span class="visually-hidden">{{ $t('btn.add') }}</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div id="share-links" class="row m-0" v-if="shares.length">
                                <div class="list-group p-0">
                                    <div v-for="item in shares" :key="item.id" class="list-group-item">
                                        <div class="d-flex w-100 justify-content-between">
                                            <span class="user lh-lg">
                                                <svg-icon icon="user"></svg-icon> {{ item.user }}
                                            </span>
                                            <span class="d-inline-block">
                                                <btn class="btn-link p-1" :icon="['far', 'clipboard']" :title="$t('btn.copy')" @click="copyLink(item.link)"></btn>
                                                <btn class="btn-link text-danger p-1" icon="trash-can" :title="$t('btn.delete')" @click="shareDelete(item.id)"></btn>
                                            </span>
                                        </div>
                                        <code>{{ item.link }}</code>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
    import FileAPI from '../../js/files.js'

    import { library } from '@fortawesome/fontawesome-svg-core'

    library.add(
        require('@fortawesome/free-regular-svg-icons/faClipboard').definition,
        require('@fortawesome/free-solid-svg-icons/faDownload').definition,
    )

    export default {
        data() {
            return {
                file: {},
                fileId: null,
                collectionId: null,
                shares: []
            }
        },
        created() {
            this.api = new FileAPI({})

            this.fileId = this.$route.params.file
            this.collectionId = this.$route.query.parent

            axios.get('/api/v4/fs/' + this.fileId, { loader: true })
                .then(response => {
                    this.file = response.data

                    if (this.file.isOwner) {
                        axios.get('api/v4/fs/' + this.fileId + '/permissions')
                            .then(response => {
                                if (response.data.list) {
                                    this.shares = response.data.list
                                }
                            })
                    }
                })
                .catch(this.$root.errorHandler)
        },
        methods: {
            copyLink(link) {
                navigator.clipboard.writeText(link);
            },
            fileDelete() {
                axios.delete('api/v4/fs/' + this.fileId)
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.$router.push({ name: 'files' })
                        }
                    })
            },
            fileDownload() {
                this.api.fileDownload(this.fileId)
            },
            shareAdd() {
                let post = { permissions: 'read-only', user: $('#user').val() }

                if (!post.user) {
                    return
                }

                axios.post('api/v4/fs/' + this.fileId + '/permissions', post)
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.shares.push(response.data)
                        }
                    })
            },
            shareDelete(id) {
                axios.delete('api/v4/fs/' + this.fileId + '/permissions/' + id)
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            this.$delete(this.shares, this.shares.findIndex(element => element.id == id))
                        }
                    })
            }
        }
    }
</script>
