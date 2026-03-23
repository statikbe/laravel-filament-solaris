<div
    x-data="{
        message: '',
        sending: false,
        send() {
            if (!this.message.trim() || this.sending) return;
            this.sending = true;
            $wire.solarisRefinePreview(this.message).then(() => {
                this.message = '';
                this.sending = false;
                this.$nextTick(() => {
                    const container = this.$refs.messages;
                    if (container) container.scrollTop = container.scrollHeight;
                });
            }).catch(() => {
                this.sending = false;
            });
        },
    }"
    class="space-y-6"
>
    {{-- Preview field displays --}}
    <x-filament-solaris::preview-fields :displays="$displays" />

    {{-- Conversation --}}
    @if (count($messages) > 0)
        <div class="border-t border-gray-200 pt-4 dark:border-white/10">
            <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">
                {{ filament_solaris_trans('conversation.heading') }}
            </h4>
            <div x-ref="messages" class="space-y-3 max-h-48 overflow-y-auto">
                @foreach ($messages as $msg)
                    <div @class([
                        'flex',
                        'justify-start' => $msg['role'] === 'assistant',
                        'justify-end' => $msg['role'] === 'user',
                    ])>
                        <div @class([
                            'rounded-lg px-3 py-2 text-sm max-w-[85%]',
                            'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-gray-100' => $msg['role'] === 'assistant',
                            'bg-primary-500 text-white' => $msg['role'] === 'user',
                        ])>
                            {{ $msg['content'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Refinement input --}}
    <div class="flex items-end gap-2">
        <div class="flex-1">
            <input
                x-model="message"
                @keydown.enter.prevent="send()"
                :disabled="sending"
                type="text"
                class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white disabled:opacity-50"
                placeholder="{{ filament_solaris_trans('conversation.refine_placeholder') }}"
            />
        </div>
        <x-filament::button
            size="sm"
            @click="send()"
            ::disabled="!message.trim() || sending"
        >
            <span x-show="!sending">
                <x-filament::icon
                    :icon="Statikbe\FilamentSolaris\Facades\FilamentSolaris::config()->getConversationSendIcon()"
                    class="h-5 w-5"
                />
            </span>
            <span x-show="sending" x-cloak>
                <x-filament::loading-indicator class="h-4 w-4" />
            </span>
        </x-filament::button>
    </div>
</div>
