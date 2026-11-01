(() => {
    const form = document.querySelector('[data-booking-form]');
    if (!(form instanceof HTMLFormElement)) return;

    const requestId = form.querySelector('[name="clientRequestId"]');
    const fingerprint = form.dataset.previewFingerprint || '';
    if (!(requestId instanceof HTMLInputElement) || fingerprint === '') return;

    const storageKey = `staybooking:booking-request:${fingerprint}`;
    let value = sessionStorage.getItem(storageKey);
    if (!value) {
        if (typeof crypto.randomUUID === 'function') {
            value = crypto.randomUUID();
        } else {
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
            value = `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
        }
        sessionStorage.setItem(storageKey, value);
    }
    requestId.value = value;

    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"]');
        if (button instanceof HTMLButtonElement) {
            button.disabled = true;
            button.textContent = 'Đang xác nhận…';
        }
    });
})();
