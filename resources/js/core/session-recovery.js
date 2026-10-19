const state = { bound: false };

export const bootSessionRecovery = () => {
    if (state.bound || !window.Livewire?.interceptRequest) return;
    state.bound = true;

    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if (response.status !== 419) return;

            preventDefault();
            const recover = document.querySelector('meta[name="flowtrack-session-recover-url"]')?.content || '/session/recover';
            window.location.replace(recover);
        });
    });
};
