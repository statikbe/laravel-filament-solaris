export default function dictationModal(statePath) {
    return {
        recording: false,
        uploading: false,
        uploaded: false,
        supported: false,
        microphoneDenied: false,
        uploadFailed: false,
        mediaRecorder: null,
        chunks: [],
        duration: 0,
        durationInterval: null,

        init() {
            this.supported = !!navigator.mediaDevices?.getUserMedia
        },

        get formattedDuration() {
            const mins = Math.floor(this.duration / 60)
            const secs = this.duration % 60
            return `${mins}:${secs.toString().padStart(2, '0')}`
        },

        get statusText() {
            if (this.uploadFailed) return 'Upload failed. Click to try again.'
            if (this.uploading) return 'Uploading...'
            if (this.recording) return 'Recording... Click to stop.'
            if (this.uploaded) return 'Recording complete \u2014 ready to submit.'
            return 'Click to start recording.'
        },

        async toggle() {
            if (this.uploading) return

            if (this.recording) {
                this.stop()
            } else {
                await this.start()
            }
        },

        async start() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
                this.mediaRecorder = new MediaRecorder(stream)
                this.chunks = []
                this.uploaded = false
                this.uploadFailed = false
                this.microphoneDenied = false
                this.duration = 0

                this.mediaRecorder.ondataavailable = (e) => {
                    if (e.data.size > 0) this.chunks.push(e.data)
                }

                this.mediaRecorder.onstop = () => {
                    const blob = new Blob(this.chunks, {
                        type: this.mediaRecorder.mimeType || 'audio/webm',
                    })
                    stream.getTracks().forEach((t) => t.stop())
                    this.chunks = []
                    this.upload(blob)
                }

                this.mediaRecorder.start()
                this.recording = true
                this.durationInterval = setInterval(() => this.duration++, 1000)
            } catch (e) {
                if (e.name === 'NotAllowedError' || e.name === 'PermissionDeniedError') {
                    this.microphoneDenied = true
                }
            }
        },

        stop() {
            clearInterval(this.durationInterval)
            this.durationInterval = null
            this.mediaRecorder?.stop()
            this.recording = false
            this.uploading = true
        },

        upload(blob) {
            const ext = blob.type.includes('mp4') ? 'mp4' : 'webm'
            const file = new File([blob], `recording.${ext}`, { type: blob.type })

            this.$wire.upload(
                statePath,
                file,
                () => {
                    this.uploading = false
                    this.uploaded = true
                },
                () => {
                    this.uploading = false
                    this.uploadFailed = true
                },
            )
        },
    }
}
