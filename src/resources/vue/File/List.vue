<template>
    <div class="container">
        <div class="card" id="files">
            <div class="card-body">
                <div class="card-title">
                    {{ $t('dashboard.files') }} <span v-if="collectionId" class="me-1">{{ ' - ' + collection.name }}</span>
                    <small><sup class="badge bg-primary">{{ $t('dashboard.beta') }}</sup></small>
                    <btn v-if="!$root.isDegraded()" class="float-end btn-outline-secondary" icon="folder" @click="createCollection">
                        {{ $t('collection.create') }}
                    </btn>
                </div>
                <div class="card-text pt-4">
                    <div id="drop-area" class="file-drop-area text-center mb-3">
                        <svg-icon icon="upload"></svg-icon> {{ $t('file.drop') }}
                    </div>
                    <div class="mb-2 d-flex w-100">
                        <list-search :placeholder="$t('file.search')" :on-search="searchFiles"></list-search>
                    </div>
                    <table class="table table-sm table-hover files">
                        <thead>
                            <tr>
                                <th scope="col" class="name">{{ $t('form.name') }}</th>
                                <th scope="col" class="size">{{ $t('form.size') }}</th>
                                <th scope="col" class="buttons"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td v-if="collectionId" colspan="3" class="name">
                                    <router-link :to="'/files' + (collection.parentId ? `/{$collection.parentId}` : '')">
                                        <svg-icon icon="folder" class="me-1"></svg-icon> ..
                                    </router-link>
                                </td>
                            </tr>
                            <tr v-for="file in files" :key="file.id" @click="$root.clickRecord">
                                <td class="name">
                                    <router-link :to="(file.type === 'collection' ? '/files/' : '/file/') + `${file.id}`">
                                        <svg-icon :icon="file.type === 'collection' ? 'folder' : ['far','file']" class="me-1" style="width:1em"></svg-icon>
                                        {{ file.name }}
                                    </router-link>
                                </td>
                                <td class="size">
                                    <span>{{ fileSize(file) }}</span>
                                </td>
                                <td class="buttons">
                                    <btn v-if="file.type !== 'collection'" class="button-download p-0 ms-1" @click="fileDownload(file)" icon="download" :title="$t('btn.download')"></btn>
                                    <btn class="button-delete text-danger p-0 ms-1" @click="fileDelete(file)" icon="trash-can" :title="$t('btn.delete')"></btn>
                                </td>
                            </tr>
                        </tbody>
                        <list-foot :colspan="3" :text="$t('file.list-empty')"></list-foot>
                    </table>
                    <list-more v-if="hasMore" :on-click="loadFiles"></list-more>
                </div>
            </div>
        </div>
        <modal-dialog id="collection-dialog" ref="collectionDialog" :title="$t('collection.new')" @click="createCollectionSubmit" :buttons="['submit']">
            <div>
                <div class="row mb-3">
                    <label for="name" class="col-sm-4 col-form-label">{{ $t('collection.name') }}</label>
                    <div class="col-sm-8">
                        <input type="text" class="form-control" id="name" v-model="form.name">
                    </div>
                </div>
            </div>
        </modal-dialog>
    </div>
</template>

<script>
    import FileAPI from '../../js/files.js'
    import ListTools from '../Widgets/ListTools'
    import ModalDialog from '../Widgets/ModalDialog'

    import { library } from '@fortawesome/fontawesome-svg-core'

    library.add(
        require('@fortawesome/free-regular-svg-icons/faFile').definition,
        require('@fortawesome/free-solid-svg-icons/faFolder').definition,
        require('@fortawesome/free-solid-svg-icons/faDownload').definition,
        require('@fortawesome/free-solid-svg-icons/faUpload').definition,
    )

    export default {
        components: {
            ModalDialog
        },
        mixins: [ ListTools ],
        beforeRouteUpdate (to, from, next) {
            this.collectionId = this.$route.params.parent
            // An event called when the route that renders this component has changed,
            // but this component is reused in the new route.
            // Required to handle links from /file/XXX to /file/YYY
            next()
            this.$parent.routerReload()
        },
        data() {
            return {
                api: {},
                collection: {},
                collectionId: '',
                files: [],
                form: {},
                uploads: {},
            }
        },
        created() {
            this.collectionId = this.$route.params.parent || ''

            if (this.collectionId) {
                axios.get('/api/v4/fs/' + this.collectionId, { loader: true })
                    .then(response => {
                        this.collection = response.data
                    })
                    .catch(this.$root.errorHandler)
            }
        },
        mounted() {
            this.api = new FileAPI({
                    dropArea: '#drop-area',
                    eventHandler: this.eventHandler,
                    parent: this.collectionId
            })

            this.loadFiles({ init: true })
        },
        methods: {
            createCollection() {
                this.form = { name: '', type: 'collection', parent: this.collectionId }
                this.$root.clearFormValidation($('#collection-dialog'))
                this.$refs.collectionDialog.show()
            },
            createCollectionSubmit() {
                this.$root.clearFormValidation($('#collection-dialog'))

                axios.post('/api/v4/fs', this.form)
                    .then(response => {
                        this.$refs.collectionDialog.hide()
                        this.$toast.success(response.data.message)
                        this.loadFiles({ reset: true })
                    })
            },
            eventHandler(name, params) {
                const camelCase = name.toLowerCase().replace(/[^a-zA-Z0-9]+(.)/g, (m, chr) => chr.toUpperCase())
                const method = camelCase + 'Handler'

                if (method in this) {
                    this[method](params)
                }
            },
            fileDelete(file) {
                axios.delete('api/v4/fs/' + file.id)
                    .then(response => {
                        if (response.data.status == 'success') {
                            this.$toast.success(response.data.message)
                            // Refresh the list
                            this.loadFiles({ reset: true })
                        }
                    })
            },
            fileDownload(file) {
                // This is not an appropriate method for big files, we can consider
                // using it still for very small files.
                // downloadFile('api/v4/fs/' + file.id + '?download=1', file.name)

                // This method first makes a request to the API to get the download URL (which does not
                // require authentication) and then use it to download the file.
                this.api.fileDownload(file.id)
            },
            fileSize(file) {
                if (file.type != 'file') {
                    return '';
                }

                return this.api.sizeText(file.size)
            },
            loadFiles(params) {
                if (this.collectionId) {
                    params['parent'] = this.collectionId
                }

                this.listSearch('files', 'api/v4/fs', params)
            },
            searchFiles(search) {
                this.loadFiles({ reset: true, search })
            },
            uploadProgressHandler(params) {
                // Note: There might be more than one event with completed=0
                // e.g. if you upload multiple files at once
                if (params.completed == 0 && !(params.id in this.uploads)) {
                    // Create the toast message with progress bar
                    this.uploads[params.id] = this.$toast.message({
                        icon: 'upload',
                        timeout: 24 * 60 * 60 * 60 * 1000,
                        title: this.$t('msg.uploading'),
                        msg: `${params.name} (${this.api.sizeText(params.total)})`,
                        progress: 0
                    })
                } else if (params.id in this.uploads) {
                    if (params.completed == 100) {
                        this.uploads[params.id].delete() // close the toast message
                        delete this.uploads[params.id]

                        // TODO: Reloading the list is probably not the best solution
                        this.loadFiles({ reset: true })
                    } else {
                        // update progress bar
                        this.uploads[params.id].updateProgress(params.completed)
                    }
                }
            }
        }
    }
</script>
