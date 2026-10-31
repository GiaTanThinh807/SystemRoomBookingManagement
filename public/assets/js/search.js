document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-search-form]').forEach((form) => {
        const list = form.querySelector('[data-room-list]');
        const add = form.querySelector('[data-add-room]');
        if (!list || !add) return;

        const refresh = () => {
            const rows = [...list.querySelectorAll('[data-room-row]')];
            rows.forEach((row, index) => {
                const number = row.querySelector('[data-room-number]');
                if (number) number.textContent = String(index + 1);
                const remove = row.querySelector('[data-remove-room]');
                if (remove) remove.hidden = rows.length === 1;
            });
            add.disabled = rows.length >= 5;
        };
        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove-room]');
            if (!button) return;
            button.closest('[data-room-row]')?.remove();
            refresh();
        });
        add.addEventListener('click', () => {
            const rows = list.querySelectorAll('[data-room-row]');
            if (rows.length >= 5) return;
            const row = rows[0].cloneNode(true);
            row.querySelectorAll('input').forEach((input) => { input.value = input.name === 'roomAdults[]' ? '2' : ''; });
            list.append(row);
            refresh();
        });
        refresh();
    });

    const root = document.querySelector('[data-availability-root]');
    if (!root) return;
    const status = root.querySelector('[data-availability-status]');
    const list = root.querySelector('[data-allocation-list]');
    const buttons = [...root.querySelectorAll('[data-payment-timing]')];
    const previewForm = root.querySelector('[data-preview-form]');
    const formatMoney = (amount) => `${new Intl.NumberFormat('vi-VN').format(Number.parseFloat(amount))} ₫`;

    const updatePreviewForm = (payload) => {
        if (!previewForm || !payload.allocation) return;
        const field = (name) => previewForm.querySelector(`[name="${name}"]`);
        field('criteria').value = JSON.stringify(payload.criteria);
        field('paymentTiming').value = payload.paymentTiming;
        field('selectionFingerprint').value = payload.allocation.selectionFingerprint;
        field('selected').value = payload.allocation.rooms
            .map((room) => `${room.roomOrder}:${room.roomTypeId}:${room.productId}`)
            .join(',');
    };

    const render = (payload) => {
        list.replaceChildren();
        if (!payload.available || !payload.allocation) {
            const empty = document.createElement('div'); empty.className = 'search-empty';
            const heading = document.createElement('h3'); heading.textContent = 'Timing này hiện không còn tổ hợp phù hợp';
            const help = document.createElement('p'); help.textContent = 'Hãy thử nhánh thanh toán còn lại hoặc đổi ngày.';
            empty.append(heading, help); list.append(empty); return;
        }
        updatePreviewForm(payload);
        const productMap = new Map(payload.property.roomProducts.map((item) => [item.productId, item]));
        payload.allocation.rooms.forEach((room) => {
            const product = productMap.get(room.productId);
            const card = document.createElement('article'); card.className = 'allocation-card allocation-card--compact';
            const info = document.createElement('div'); info.className = 'allocation-card__info';
            const kicker = document.createElement('span'); kicker.textContent = `Phòng ${room.roomOrder}`;
            const name = document.createElement('h3'); name.textContent = product?.roomTypeName || 'Phòng phù hợp';
            const guests = document.createElement('p'); guests.textContent = `${room.party.adults} người lớn · ${room.party.childAges.length} trẻ em`;
            info.append(kicker, name, guests);
            const productInfo = document.createElement('div'); productInfo.className = 'allocation-card__product';
            const productName = document.createElement('strong'); productName.textContent = product?.productName || 'Lựa chọn phòng';
            const payment = document.createElement('span'); payment.textContent = payload.paymentTiming === 'PAY_AT_PROPERTY' ? 'Trả tại cơ sở' : 'Trả trực tuyến';
            productInfo.append(productName, payment);
            const price = document.createElement('div'); price.className = 'allocation-card__price';
            const total = document.createElement('strong'); total.textContent = formatMoney(room.priceBreakdown.grandTotal.amount);
            const note = document.createElement('small'); note.textContent = 'Đã gồm thuế và phí'; price.append(total, note);
            card.append(info, productInfo, price); list.append(card);
        });
        const total = document.createElement('div'); total.className = 'allocation-total';
        const label = document.createElement('span'); label.textContent = `Tổng cho ${payload.allocation.rooms.length} phòng`;
        const amount = document.createElement('strong'); amount.textContent = formatMoney(payload.allocation.grandTotal.amount);
        total.append(label, amount);
        if (previewForm) {
            const next = document.createElement('button');
            next.className = 'button button--primary'; next.type = 'submit'; next.setAttribute('form', previewForm.id);
            next.textContent = 'Tiếp tục xác nhận'; total.append(next);
        } else if (root.dataset.loginUrl) {
            const login = document.createElement('a'); login.className = 'button button--primary';
            login.href = root.dataset.loginUrl; login.textContent = 'Đăng nhập để tiếp tục'; total.append(login);
        }
        list.append(total);
    };

    buttons.forEach((button) => button.addEventListener('click', async () => {
        if (button.disabled || button.classList.contains('is-active')) return;
        buttons.forEach((item) => { item.disabled = true; });
        status.textContent = 'Đang kiểm tra lại giá và tình trạng phòng…';
        const endpoint = new URL(root.dataset.endpoint, window.location.origin);
        endpoint.search = window.location.search;
        endpoint.searchParams.set('paymentTiming', button.dataset.paymentTiming);
        endpoint.searchParams.delete('selectionFingerprint'); endpoint.searchParams.delete('selected');
        try {
            const response = await fetch(endpoint, { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error?.message || 'Không thể kiểm tra phòng.');
            buttons.forEach((item) => item.classList.toggle('is-active', item === button));
            buttons.forEach((item) => {
                const summary = payload.timingSummary?.[item.dataset.paymentTiming];
                if (summary) item.dataset.available = summary.available ? 'true' : 'false';
            });
            status.textContent = payload.selectionChanged ? payload.changeReason : 'Giá và tồn vừa được kiểm tra lại.';
            render(payload);
            const pageUrl = new URL(window.location.href);
            pageUrl.searchParams.set('paymentTiming', button.dataset.paymentTiming);
            pageUrl.searchParams.delete('selectionFingerprint'); pageUrl.searchParams.delete('selected');
            window.history.replaceState({}, '', pageUrl);
        } catch (error) {
            status.textContent = error.message;
        } finally {
            buttons.forEach((item) => { item.disabled = item.dataset.available === 'false'; });
        }
    }));
});
