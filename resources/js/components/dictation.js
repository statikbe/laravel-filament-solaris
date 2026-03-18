export default function dictation({ actionName }) {
    return {
        recording: false,
        processing: false,
        mediaRecorder: null,
        chunks: [],
        supported: false,

        init() {
            this.supported = !!navigator.mediaDevices?.getUserMedia
        },

        async toggle() {
            if (this.processing) {
                return
            }

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

                this.mediaRecorder.ondataavailable = (e) => {
                    if (e.data.size > 0) this.chunks.push(e.data)
                }

                this.mediaRecorder.onstop = () => {
                    const blob = new Blob(this.chunks, { type: this.mediaRecorder.mimeType || 'audio/webm' })
                    stream.getTracks().forEach(t => t.stop())
                    this.chunks = []
                    this.upload(blob)
                }

                this.mediaRecorder.start()
                this.recording = true
            } catch (e) {
                if (e.name === 'NotAllowedError' || e.name === 'PermissionDeniedError') {
                    this.$wire.mountAction(actionName, { __dictationError: 'microphone_denied' })
                }
            }
        },

        stop() {
            this.mediaRecorder?.stop()
            this.recording = false
            this.processing = true
        },

        upload(blob) {
            const extension = blob.type.includes('mp4') ? 'mp4' : 'webm'
            const file = new File([blob], `recording.${extension}`, { type: blob.type })

            this.$wire.upload(
                'componentFileAttachments.dictation_audio',
                file,
                () => {
                    this.$wire.mountAction(actionName)
                    this.processing = false
                },
                () => {
                    this.processing = false
                    this.$wire.mountAction(actionName, { __dictationError: 'upload_failed' })
                },
            )
        },
    }
}
