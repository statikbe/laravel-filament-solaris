@props(['wireModel'])

<div
    x-data="{
        files: [],
        pickFiles(event) {
            const dt = new DataTransfer();
            [...this.files, ...Array.from(event.target.files)].forEach(f => dt.items.add(f));
            this.files = Array.from(dt.files);
            this.$refs.input.files = dt.files;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
            event.target.value = '';
        },
        removeFile(index) {
            const dt = new DataTransfer();
            this.files.filter((_, i) => i !== index).forEach(f => dt.items.add(f));
            this.files = Array.from(dt.files);
            this.$refs.input.files = dt.files;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },
        clear() {
            this.files = [];
            const dt = new DataTransfer();
            this.$refs.input.files = dt.files;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },
    }"
    @solaris-clear-refinement-attachments.window="clear()"
    class="flex items-center gap-2 flex-wrap"
>
    <input
        x-ref="input"
        @change="pickFiles($event)"
        type="file"
        multiple
        wire:model="{{ $wireModel }}"
        class="hidden"
    />

    <button
        type="button"
        @click="$refs.input.click()"
        class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white p-1.5 text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10"
        :title="@js(filament_solaris_trans('conversation.attach_files'))"
    >
        <x-filament::icon
            :icon="\Statikbe\FilamentSolaris\Facades\FilamentSolaris::config()->getConversationAttachmentIcon()"
            class="h-4 w-4"
        />
    </button>

    <template x-for="(file, index) in files" :key="index">
        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-700 dark:bg-white/10 dark:text-gray-300">
            <span x-text="file.name"></span>
            <button type="button" @click="removeFile(index)" class="hover:text-red-500" aria-label="Remove">
                &times;
            </button>
        </span>
    </template>
</div>
