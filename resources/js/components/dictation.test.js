import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import dictationModal from './dictation.js'

/**
 * Helper: creates a dictationModal component instance with mocked $wire.
 */
function createComponent(statePath = 'componentFileAttachments.dictation_audio') {
    const wire = {
        upload: vi.fn(),
    }

    const component = dictationModal(statePath)
    component.$wire = wire

    return { component, wire }
}

/**
 * Helper: creates a mock MediaRecorder that can be controlled in tests.
 */
function mockMediaRecorder() {
    const stopTrack = vi.fn()
    const stream = {
        getTracks: () => [{ stop: stopTrack }],
    }

    let instance = null

    const MockMediaRecorder = vi.fn(function () {
        instance = this
        this.start = vi.fn()
        this.stop = vi.fn(() => {
            // Simulate the browser calling onstop asynchronously
            if (this.onstop) this.onstop()
        })
        this.mimeType = 'audio/webm'
        this.ondataavailable = null
        this.onstop = null
    })

    return {
        MockMediaRecorder,
        stream,
        stopTrack,
        getInstance: () => instance,
    }
}

/**
 * Helper: sets up navigator.mediaDevices.getUserMedia mock.
 */
function mockGetUserMedia(stream) {
    const getUserMedia = vi.fn().mockResolvedValue(stream)
    vi.stubGlobal('navigator', {
        mediaDevices: { getUserMedia },
    })
    return getUserMedia
}

describe('dictationModal Alpine component', () => {
    beforeEach(() => {
        vi.restoreAllMocks()
        vi.unstubAllGlobals()
        vi.useFakeTimers()
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    describe('init', () => {
        it('sets supported to true when getUserMedia is available', () => {
            vi.stubGlobal('navigator', {
                mediaDevices: { getUserMedia: vi.fn() },
            })

            const { component } = createComponent()
            component.init()

            expect(component.supported).toBe(true)
        })

        it('sets supported to false when mediaDevices is undefined', () => {
            vi.stubGlobal('navigator', {})

            const { component } = createComponent()
            component.init()

            expect(component.supported).toBe(false)
        })

        it('sets supported to false when navigator has no mediaDevices', () => {
            vi.stubGlobal('navigator', { mediaDevices: null })

            const { component } = createComponent()
            component.init()

            expect(component.supported).toBe(false)
        })
    })

    describe('formattedDuration', () => {
        it('formats 0 seconds as 0:00', () => {
            const { component } = createComponent()
            component.duration = 0

            expect(component.formattedDuration).toBe('0:00')
        })

        it('formats seconds with zero-padding', () => {
            const { component } = createComponent()
            component.duration = 5

            expect(component.formattedDuration).toBe('0:05')
        })

        it('formats minutes and seconds', () => {
            const { component } = createComponent()
            component.duration = 125

            expect(component.formattedDuration).toBe('2:05')
        })
    })

    describe('statusText', () => {
        it('shows idle text by default', () => {
            const { component } = createComponent()

            expect(component.statusText).toBe('Click to start recording.')
        })

        it('shows recording text when recording', () => {
            const { component } = createComponent()
            component.recording = true

            expect(component.statusText).toBe('Recording... Click to stop.')
        })

        it('shows uploading text when uploading', () => {
            const { component } = createComponent()
            component.uploading = true

            expect(component.statusText).toBe('Uploading...')
        })

        it('shows ready text when uploaded', () => {
            const { component } = createComponent()
            component.uploaded = true

            expect(component.statusText).toBe('Recording complete \u2014 ready to submit.')
        })

        it('shows upload failed text', () => {
            const { component } = createComponent()
            component.uploadFailed = true

            expect(component.statusText).toBe('Upload failed. Click to try again.')
        })
    })

    describe('toggle', () => {
        it('calls start when not recording', async () => {
            const { stream, MockMediaRecorder } = mockMediaRecorder()
            mockGetUserMedia(stream)
            vi.stubGlobal('MediaRecorder', MockMediaRecorder)

            const { component } = createComponent()
            await component.toggle()

            expect(component.recording).toBe(true)
            expect(MockMediaRecorder).toHaveBeenCalledWith(stream)
        })

        it('calls stop when recording', async () => {
            const { component } = createComponent()
            component.recording = true
            component.mediaRecorder = { stop: vi.fn() }

            await component.toggle()

            expect(component.recording).toBe(false)
            expect(component.uploading).toBe(true)
            expect(component.mediaRecorder.stop).toHaveBeenCalled()
        })

        it('is a no-op while uploading', async () => {
            const { component } = createComponent()
            component.uploading = true
            component.recording = false

            await component.toggle()

            expect(component.recording).toBe(false)
        })
    })

    describe('start', () => {
        it('requests microphone access with audio only', async () => {
            const { stream, MockMediaRecorder } = mockMediaRecorder()
            const getUserMedia = mockGetUserMedia(stream)
            vi.stubGlobal('MediaRecorder', MockMediaRecorder)

            const { component } = createComponent()
            await component.start()

            expect(getUserMedia).toHaveBeenCalledWith({ audio: true })
        })

        it('creates a MediaRecorder and starts it', async () => {
            const { stream, MockMediaRecorder, getInstance } = mockMediaRecorder()
            mockGetUserMedia(stream)
            vi.stubGlobal('MediaRecorder', MockMediaRecorder)

            const { component } = createComponent()
            await component.start()

            expect(getInstance().start).toHaveBeenCalled()
            expect(component.recording).toBe(true)
            expect(component.chunks).toEqual([])
        })

        it('resets state on new recording', async () => {
            const { stream, MockMediaRecorder } = mockMediaRecorder()
            mockGetUserMedia(stream)
            vi.stubGlobal('MediaRecorder', MockMediaRecorder)

            const { component } = createComponent()
            component.uploaded = true
            component.uploadFailed = true
            component.duration = 42

            await component.start()

            expect(component.uploaded).toBe(false)
            expect(component.uploadFailed).toBe(false)
            expect(component.duration).toBe(0)
        })

        it('starts a duration timer', async () => {
            const { stream, MockMediaRecorder } = mockMediaRecorder()
            mockGetUserMedia(stream)
            vi.stubGlobal('MediaRecorder', MockMediaRecorder)

            const { component } = createComponent()
            await component.start()

            expect(component.duration).toBe(0)

            vi.advanceTimersByTime(3000)

            expect(component.duration).toBe(3)
        })

        it('collects chunks on dataavailable', async () => {
            const { stream, MockMediaRecorder, getInstance } = mockMediaRecorder()
            mockGetUserMedia(stream)
            vi.stubGlobal('MediaRecorder', MockMediaRecorder)

            const { component } = createComponent()
            await component.start()

            const recorder = getInstance()
            const chunk = new Blob(['audio-data'], { type: 'audio/webm' })
            recorder.ondataavailable({ data: chunk })

            expect(component.chunks).toHaveLength(1)
            expect(component.chunks[0]).toBe(chunk)
        })

        it('ignores empty data chunks', async () => {
            const { stream, MockMediaRecorder, getInstance } = mockMediaRecorder()
            mockGetUserMedia(stream)
            vi.stubGlobal('MediaRecorder', MockMediaRecorder)

            const { component } = createComponent()
            await component.start()

            const recorder = getInstance()
            recorder.ondataavailable({ data: { size: 0 } })

            expect(component.chunks).toHaveLength(0)
        })

        it('sets microphoneDenied on NotAllowedError', async () => {
            const error = new DOMException('Permission denied', 'NotAllowedError')
            vi.stubGlobal('navigator', {
                mediaDevices: { getUserMedia: vi.fn().mockRejectedValue(error) },
            })

            const { component } = createComponent()
            await component.start()

            expect(component.microphoneDenied).toBe(true)
            expect(component.recording).toBe(false)
        })

        it('sets microphoneDenied on PermissionDeniedError', async () => {
            const error = new DOMException('Denied', 'PermissionDeniedError')
            vi.stubGlobal('navigator', {
                mediaDevices: { getUserMedia: vi.fn().mockRejectedValue(error) },
            })

            const { component } = createComponent()
            await component.start()

            expect(component.microphoneDenied).toBe(true)
        })

        it('does not set microphoneDenied on other errors', async () => {
            const error = new Error('Some other error')
            vi.stubGlobal('navigator', {
                mediaDevices: { getUserMedia: vi.fn().mockRejectedValue(error) },
            })

            const { component } = createComponent()
            await component.start()

            expect(component.microphoneDenied).toBe(false)
            expect(component.recording).toBe(false)
        })
    })

    describe('stop', () => {
        it('stops the MediaRecorder and transitions state', () => {
            const { component } = createComponent()
            const stopFn = vi.fn()
            component.mediaRecorder = { stop: stopFn }
            component.recording = true

            component.stop()

            expect(stopFn).toHaveBeenCalled()
            expect(component.recording).toBe(false)
            expect(component.uploading).toBe(true)
        })

        it('clears the duration interval', async () => {
            const { stream, MockMediaRecorder } = mockMediaRecorder()
            mockGetUserMedia(stream)
            vi.stubGlobal('MediaRecorder', MockMediaRecorder)

            const { component } = createComponent()
            await component.start()

            vi.advanceTimersByTime(2000)
            expect(component.duration).toBe(2)

            component.stop()

            vi.advanceTimersByTime(3000)
            // Duration should not increase after stop
            expect(component.duration).toBe(2)
        })

        it('handles null mediaRecorder gracefully', () => {
            const { component } = createComponent()
            component.mediaRecorder = null

            expect(() => component.stop()).not.toThrow()
            expect(component.uploading).toBe(true)
        })
    })

    describe('onstop callback (recording → upload flow)', () => {
        it('creates blob from chunks, stops tracks, clears chunks, and calls upload', async () => {
            const { stream, MockMediaRecorder, getInstance, stopTrack } = mockMediaRecorder()
            mockGetUserMedia(stream)
            vi.stubGlobal('MediaRecorder', MockMediaRecorder)

            const { component, wire } = createComponent()
            await component.start()

            // Simulate data arriving
            const recorder = getInstance()
            const chunk = new Blob(['audio'], { type: 'audio/webm' })
            recorder.ondataavailable({ data: chunk })

            expect(component.chunks).toHaveLength(1)

            // Simulate stop — the mock calls onstop synchronously
            recorder.onstop()

            // Tracks should be stopped
            expect(stopTrack).toHaveBeenCalled()

            // Chunks should be cleared after onstop
            expect(component.chunks).toHaveLength(0)

            // Upload should have been called
            expect(wire.upload).toHaveBeenCalledWith(
                'componentFileAttachments.dictation_audio',
                expect.any(File),
                expect.any(Function),
                expect.any(Function),
            )
        })
    })

    describe('upload', () => {
        it('creates a File with webm extension for audio/webm', () => {
            const { component, wire } = createComponent()
            const blob = new Blob(['data'], { type: 'audio/webm' })

            component.upload(blob)

            const uploadedFile = wire.upload.mock.calls[0][1]
            expect(uploadedFile).toBeInstanceOf(File)
            expect(uploadedFile.name).toBe('recording.webm')
            expect(uploadedFile.type).toBe('audio/webm')
        })

        it('creates a File with mp4 extension for audio/mp4 (Safari)', () => {
            const { component, wire } = createComponent()
            const blob = new Blob(['data'], { type: 'audio/mp4' })

            component.upload(blob)

            const uploadedFile = wire.upload.mock.calls[0][1]
            expect(uploadedFile.name).toBe('recording.mp4')
        })

        it('uploads to the provided statePath', () => {
            const { component, wire } = createComponent('componentFileAttachments.dictation_audio')
            const blob = new Blob(['data'], { type: 'audio/webm' })

            component.upload(blob)

            expect(wire.upload.mock.calls[0][0]).toBe('componentFileAttachments.dictation_audio')
        })

        it('uploads to a custom statePath', () => {
            const { component, wire } = createComponent('custom.path')
            const blob = new Blob(['data'], { type: 'audio/webm' })

            component.upload(blob)

            expect(wire.upload.mock.calls[0][0]).toBe('custom.path')
        })

        it('sets uploaded to true on successful upload', () => {
            const { component, wire } = createComponent()
            const blob = new Blob(['data'], { type: 'audio/webm' })

            component.upload(blob)

            // Call the success callback (3rd argument)
            const onSuccess = wire.upload.mock.calls[0][2]
            onSuccess()

            expect(component.uploaded).toBe(true)
            expect(component.uploading).toBe(false)
        })

        it('sets uploadFailed on upload failure', () => {
            const { component, wire } = createComponent()
            const blob = new Blob(['data'], { type: 'audio/webm' })

            component.upload(blob)

            // Call the error callback (4th argument)
            const onError = wire.upload.mock.calls[0][3]
            onError()

            expect(component.uploadFailed).toBe(true)
            expect(component.uploading).toBe(false)
        })
    })
})
