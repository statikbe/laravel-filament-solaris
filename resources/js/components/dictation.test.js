import { describe, it, expect, vi, beforeEach } from 'vitest'
import dictation from './dictation.js'

/**
 * Helper: creates a dictation component instance with mocked $wire.
 */
function createComponent(actionName = 'dictate') {
    const wire = {
        upload: vi.fn(),
        mountAction: vi.fn(),
    }

    const component = dictation({ actionName })
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

describe('dictation Alpine component', () => {
    beforeEach(() => {
        vi.restoreAllMocks()
        vi.unstubAllGlobals()
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
            expect(component.processing).toBe(true)
            expect(component.mediaRecorder.stop).toHaveBeenCalled()
        })

        it('is a no-op while processing', async () => {
            const { component } = createComponent()
            component.processing = true
            component.recording = false

            await component.toggle()

            expect(component.recording).toBe(false)
        })

        it('is a no-op while processing even if recording was somehow true', async () => {
            const { component } = createComponent()
            component.processing = true
            component.recording = true

            await component.toggle()

            // recording remains unchanged because toggle returned early
            expect(component.recording).toBe(true)
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

        it('dispatches microphone_denied error on NotAllowedError', async () => {
            const error = new DOMException('Permission denied', 'NotAllowedError')
            vi.stubGlobal('navigator', {
                mediaDevices: { getUserMedia: vi.fn().mockRejectedValue(error) },
            })

            const { component, wire } = createComponent('dictate')
            await component.start()

            expect(wire.mountAction).toHaveBeenCalledWith('dictate', {
                __dictationError: 'microphone_denied',
            })
            expect(component.recording).toBe(false)
        })

        it('dispatches microphone_denied error on PermissionDeniedError', async () => {
            const error = new DOMException('Denied', 'PermissionDeniedError')
            vi.stubGlobal('navigator', {
                mediaDevices: { getUserMedia: vi.fn().mockRejectedValue(error) },
            })

            const { component, wire } = createComponent('voice')
            await component.start()

            expect(wire.mountAction).toHaveBeenCalledWith('voice', {
                __dictationError: 'microphone_denied',
            })
        })

        it('does not dispatch on other errors', async () => {
            const error = new Error('Some other error')
            vi.stubGlobal('navigator', {
                mediaDevices: { getUserMedia: vi.fn().mockRejectedValue(error) },
            })

            const { component, wire } = createComponent()
            await component.start()

            expect(wire.mountAction).not.toHaveBeenCalled()
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
            expect(component.processing).toBe(true)
        })

        it('handles null mediaRecorder gracefully', () => {
            const { component } = createComponent()
            component.mediaRecorder = null

            expect(() => component.stop()).not.toThrow()
            expect(component.processing).toBe(true)
        })
    })

    describe('onstop callback (recording → upload flow)', () => {
        it('creates blob from chunks, stops tracks, clears chunks, and calls upload', async () => {
            const { stream, MockMediaRecorder, getInstance, stopTrack } = mockMediaRecorder()
            mockGetUserMedia(stream)
            vi.stubGlobal('MediaRecorder', MockMediaRecorder)

            const { component, wire } = createComponent('dictate')
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
            const { component, wire } = createComponent('dictate')
            const blob = new Blob(['data'], { type: 'audio/webm' })

            component.upload(blob)

            const uploadedFile = wire.upload.mock.calls[0][1]
            expect(uploadedFile).toBeInstanceOf(File)
            expect(uploadedFile.name).toBe('recording.webm')
            expect(uploadedFile.type).toBe('audio/webm')
        })

        it('creates a File with mp4 extension for audio/mp4 (Safari)', () => {
            const { component, wire } = createComponent('dictate')
            const blob = new Blob(['data'], { type: 'audio/mp4' })

            component.upload(blob)

            const uploadedFile = wire.upload.mock.calls[0][1]
            expect(uploadedFile.name).toBe('recording.mp4')
        })

        it('uploads to componentFileAttachments.dictation_audio', () => {
            const { component, wire } = createComponent('dictate')
            const blob = new Blob(['data'], { type: 'audio/webm' })

            component.upload(blob)

            expect(wire.upload.mock.calls[0][0]).toBe('componentFileAttachments.dictation_audio')
        })

        it('calls mountAction on successful upload', () => {
            const { component, wire } = createComponent('my-dictation')
            const blob = new Blob(['data'], { type: 'audio/webm' })

            component.upload(blob)

            // Call the success callback (3rd argument)
            const onSuccess = wire.upload.mock.calls[0][2]
            onSuccess()

            expect(wire.mountAction).toHaveBeenCalledWith('my-dictation')
            expect(component.processing).toBe(false)
        })

        it('resets processing and dispatches error on upload failure', () => {
            const { component, wire } = createComponent('dictate')
            component.processing = true
            const blob = new Blob(['data'], { type: 'audio/webm' })

            component.upload(blob)

            // Call the error callback (4th argument)
            const onError = wire.upload.mock.calls[0][3]
            onError()

            expect(component.processing).toBe(false)
            expect(wire.mountAction).toHaveBeenCalledWith('dictate', {
                __dictationError: 'upload_failed',
            })
        })
    })
})
