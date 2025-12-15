
function FileAPI(params = {})
{
    // Initial max size of a file chunk in an upload request
    // Note: The value may change to the value provided by the server on the first upload.
    // Note: That chunk size here is only body, Swoole's package_max_length is body + headers,
    // so don't forget to subtract some margin (e.g. 8KB)
    let maxChunkSize = window.config['maxChunkSize'] || (5 * 1024 * 1024 - 1024 * 8)

    const area = $(params.dropArea)

    // Add hidden input to the drop area, so we can handle upload by click
    const input = $('<input>')
        .attr({type: 'file', multiple: true, style: 'visibility: hidden'})
        .on('change', event => { fileDropHandler(event) })
        .appendTo(area)
        .get(0)

    // Register events on the upload area element
    area.on('click', () => input.click())
        .on('drop', event => fileDropHandler(event))
        .on('dragenter dragleave drop', event => fileDragHandler(event))
        .on('dragover', event => event.preventDefault()) // prevents file from being opened on drop)

    // Handle dragging on the whole page, so we can style the area in a different way
    $(document.documentElement).off('.fileapi')
        .on('dragenter.fileapi dragleave.fileapi', event => area.toggleClass('dragactive'))

    // Handle dragging file(s) - style the upload area element
    const fileDragHandler = (event) => {
        if (event.type == 'drop') {
            area.removeClass('dragover dragactive')
        } else {
            area[event.type == 'dragenter' ? 'addClass' : 'removeClass']('dragover')
        }
    }

    // Handler for both a ondrop event and file input onchange event
    const fileDropHandler = (event) => {
        let files = event.target.files || event.dataTransfer.files

        if (!files || !files.length) {
            return
        }

        // Prevent default behavior (prevent file from being opened on drop)
        event.preventDefault();

        // TODO: Check file size limit, limit number of files to upload at once?

        // For every file...
        for (const file of files) {
            const progress = {
                id: Date.now(),
                name: file.name,
                total: file.size,
                completed: 0
            }

            file.uploaded = 0

            // Upload request configuration
            const config = {
                onUploadProgress: progressEvent => {
                    progress.completed = Math.round(((file.uploaded + progressEvent.loaded) * 100) / file.size)

                    // Don't trigger the event when 100% of the file has been sent
                    // We need to wait until the request response is available, then
                    // we'll trigger it (see below where the axios request is created)
                    if (progress.completed < 100) {
                        params.eventHandler('upload-progress', progress)
                    }
                },
                headers: {
                    'Content-Type': file.type
                },
                ignoreErrors: true, // skip the Kolab4 interceptor
                params: { name: file.name },
                maxBodyLength: -1, // no limit
                timeout: 0, // no limit
                transformRequest: [] // disable body transformation
            }

            // FIXME: It might be better to handle multiple-files upload as a one
            // "progress source", i.e. we might want to refresh the files list once
            // all files finished uploading, not multiple times in the middle
            // of the upload process.

            params.eventHandler('upload-progress', progress)

            // A "recursive" function to upload the file in chunks (if needed)
            const uploadFn = (start = 0, uploadId) => {
                let url = 'api/v4/fs'
                let body = ''

                if (file.size <= maxChunkSize) {
                    // The file is small, we'll upload it using a single request
                    // Note that even in this case the auth token might expire while
                    // the file is uploading, but the risk is quite small.
                    body = file
                    start += maxChunkSize
                    if (params.parent) {
                        url = url + '?parent=' + params.parent
                    }
                } else if (!uploadId) {
                    // The file is big, first send a request for the upload location
                    // The upload location does not require authentication, which means
                    // there should be no problem with expired auth token, etc.
                    config.params.media = 'resumable'
                    config.params.size = file.size
                    if (params.parent) {
                        config.params.parent = params.parent
                    }
                } else {
                    // Upload a chunk of the file to the upload location
                    url = 'api/v4/fs/uploads/' + uploadId
                    body = file.slice(start, start + maxChunkSize, file.type)

                    config.params = { from: start }
                    config.headers.Authorization = ''
                    start += maxChunkSize
                }

                axios.post(url, body, config)
                    .then(response => {
                        if (response.data.maxChunkSize) {
                            maxChunkSize = response.data.maxChunkSize
                        }

                        if (start < file.size) {
                            file.uploaded = start
                            uploadFn(start, uploadId || response.data.uploadId)
                        } else {
                            progress.completed = 100
                            params.eventHandler('upload-progress', progress)
                        }
                    })
                    .catch(error => {
                        console.log(error)

                        // TODO: Depending on the error consider retrying the request
                        // if it was one of many chunks of a bigger file?

                        progress.error = error
                        progress.completed = 100
                        params.eventHandler('upload-progress', progress)
                    })
            }

            // Start uploading
            uploadFn()
        }
    }

    /**
     * Download a file. Starts downloading using a hidden link trick.
     */
    this.fileDownload = (id) => {
        axios.get('api/v4/fs/' + id + '?downloadUrl=1')
            .then(response => {
                // Create a dummy link element and click it
                if (response.data.downloadUrl) {
                    $('<a>').attr('href', response.data.downloadUrl).get(0).click()
                }
            })
    }

    /**
     * Rename a file.
     */
    this.fileRename = (id, name) => {
        axios.put('api/v4/fs/' + id, { name })
            .then(response => {

            })
    }

    /**
     * Convert file size as a number of bytes to a human-readable format
     */
    this.sizeText = (bytes) => {
        if (bytes >= 1073741824)
            return parseFloat(bytes/1073741824).toFixed(2) + ' GB';
        if (bytes >= 1048576)
            return parseFloat(bytes/1048576).toFixed(2) + ' MB';
        if (bytes >= 1024)
            return parseInt(bytes/1024) + ' kB';

        return parseInt(bytes || 0) + ' B';
    }
}

export default FileAPI
