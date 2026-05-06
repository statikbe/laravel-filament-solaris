<?php

use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Statikbe\FilamentSolaris\Factories\FileUploadFactory;

it('resolves image type from MIME', function () {
    expect(FileUploadFactory::resolveAttachmentType('whatever.bin', 'image/png'))->toBe(Image::class)
        ->and(FileUploadFactory::resolveAttachmentType('photo.bin', 'image/jpeg'))->toBe(Image::class)
        ->and(FileUploadFactory::resolveAttachmentType('icon.bin', 'image/webp'))->toBe(Image::class);
});

it('resolves audio type from MIME', function () {
    expect(FileUploadFactory::resolveAttachmentType('clip.bin', 'audio/mpeg'))->toBe(Audio::class)
        ->and(FileUploadFactory::resolveAttachmentType('recording.bin', 'audio/wav'))->toBe(Audio::class)
        ->and(FileUploadFactory::resolveAttachmentType('voice.bin', 'audio/mp4'))->toBe(Audio::class);
});

it('falls back to document type for non-image non-audio MIME', function () {
    expect(FileUploadFactory::resolveAttachmentType('contract.bin', 'application/pdf'))->toBe(Document::class)
        ->and(FileUploadFactory::resolveAttachmentType('notes.bin', 'text/plain'))->toBe(Document::class)
        ->and(FileUploadFactory::resolveAttachmentType('unknown.bin', 'application/octet-stream'))->toBe(Document::class);
});

it('resolves type from extension when MIME is null', function () {
    expect(FileUploadFactory::resolveAttachmentType('cover.png', null))->toBe(Image::class)
        ->and(FileUploadFactory::resolveAttachmentType('photo.JPG', null))->toBe(Image::class)
        ->and(FileUploadFactory::resolveAttachmentType('intro.mp3', null))->toBe(Audio::class)
        ->and(FileUploadFactory::resolveAttachmentType('spec.pdf', null))->toBe(Document::class)
        ->and(FileUploadFactory::resolveAttachmentType('notes.md', null))->toBe(Document::class);
});

it('recognises common image extensions', function () {
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg', 'heic', 'avif'] as $ext) {
        expect(FileUploadFactory::resolveAttachmentType("file.{$ext}", null))->toBe(Image::class);
    }
});

it('recognises common audio extensions', function () {
    foreach (['mp3', 'wav', 'm4a', 'ogg', 'flac', 'aac', 'opus'] as $ext) {
        expect(FileUploadFactory::resolveAttachmentType("file.{$ext}", null))->toBe(Audio::class);
    }
});

it('defaults to document when neither MIME nor extension matches', function () {
    expect(FileUploadFactory::resolveAttachmentType('LICENSE', null))->toBe(Document::class)
        ->and(FileUploadFactory::resolveAttachmentType('archive.tar.gz', null))->toBe(Document::class);
});

// --- toAttachments() ---

it('returns empty array for null or empty value', function () {
    expect(FileUploadFactory::toAttachments(null, null))->toBe([])
        ->and(FileUploadFactory::toAttachments([], null))->toBe([])
        ->and(FileUploadFactory::toAttachments('', null))->toBe([]);
});

it('builds StoredImage attachments from a string path', function () {
    $result = FileUploadFactory::toAttachments('uploads/photo.png', 'public');

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(StoredImage::class);
});

it('builds StoredDocument attachments from a PDF path', function () {
    $result = FileUploadFactory::toAttachments('contracts/spec.pdf', 'public');

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(StoredDocument::class);
});

it('builds StoredAudio attachments from an audio path', function () {
    $result = FileUploadFactory::toAttachments('voice/intro.mp3', 'public');

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(StoredAudio::class);
});

it('builds multiple stored attachments from an array of paths', function () {
    $result = FileUploadFactory::toAttachments(['uploads/a.png', 'uploads/b.pdf', 'uploads/c.mp3'], 'public');

    expect($result)->toHaveCount(3)
        ->and($result[0])->toBeInstanceOf(StoredImage::class)
        ->and($result[1])->toBeInstanceOf(StoredDocument::class)
        ->and($result[2])->toBeInstanceOf(StoredAudio::class);
});

it('builds Base64Image attachments from a TemporaryUploadedFile (image)', function () {
    $tempFile = createTempUploadedFile('photo.png', 'image/png', 'fake-png-bytes');

    $result = FileUploadFactory::toAttachments([uniqid() => $tempFile], null);

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(Base64Image::class);
});

it('builds Base64Document attachments from a TemporaryUploadedFile (PDF)', function () {
    $tempFile = createTempUploadedFile('spec.pdf', 'application/pdf', '%PDF-1.4 fake');

    $result = FileUploadFactory::toAttachments([uniqid() => $tempFile], null);

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(Base64Document::class);
});

it('builds Base64Audio attachments from a TemporaryUploadedFile (audio)', function () {
    $tempFile = createTempUploadedFile('clip.mp3', 'audio/mpeg', 'fake-mp3-bytes');

    $result = FileUploadFactory::toAttachments([uniqid() => $tempFile], null);

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(Base64Audio::class);
});

it('builds multiple Base64 attachments from a multi-upload state map', function () {
    $state = [
        uniqid() => createTempUploadedFile('a.png', 'image/png', 'a'),
        uniqid() => createTempUploadedFile('b.pdf', 'application/pdf', 'b'),
    ];

    $result = FileUploadFactory::toAttachments($state, null);

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBeInstanceOf(Base64Image::class)
        ->and($result[1])->toBeInstanceOf(Base64Document::class);
});
