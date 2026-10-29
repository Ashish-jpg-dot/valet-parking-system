(() => {
    const tabButtons = [...document.querySelectorAll('[role="tab"]')];
    const panels = [...document.querySelectorAll('[data-panel]')];

    const showTab = (name, moveFocus = false) => {
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.panel !== name;
            const visibleTab = tabButtons.find((button) =>
                button.dataset.tab === panel.dataset.panel && button.getClientRects().length > 0
            );
            if (visibleTab) panel.setAttribute('aria-labelledby', visibleTab.id);
        });
        tabButtons.forEach((button) => {
            const selected = button.dataset.tab === name;
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
            if (selected && moveFocus) button.focus();
        });

        const url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        window.history.replaceState({}, '', url);
        window.scrollTo({ top: 0, behavior: 'smooth' });
        window.dispatchEvent(new CustomEvent('vf:tabchange', { detail: { name } }));
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => showTab(button.dataset.tab));
        button.addEventListener('keydown', (event) => {
            const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
            if (!keys.includes(event.key)) return;
            event.preventDefault();
            const siblings = tabButtons.filter((item) => item.closest('[role="tablist"]') === button.closest('[role="tablist"]'));
            const index = siblings.indexOf(button);
            let nextIndex = index;
            if (event.key === 'ArrowRight') nextIndex = (index + 1) % siblings.length;
            if (event.key === 'ArrowLeft') nextIndex = (index - 1 + siblings.length) % siblings.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = siblings.length - 1;
            showTab(siblings[nextIndex].dataset.tab, true);
        });
    });

    document.querySelectorAll('[data-open-tab]').forEach((button) => {
        button.addEventListener('click', () => showTab(button.dataset.openTab));
    });

    const fuelRadios = [...document.querySelectorAll('input[name="fuel_service"]')];
    const fuelAmount = document.querySelector('#fuel-amount');
    const totalFare = document.querySelector('#total-fare');
    const fuelBadge = document.querySelector('#fuel-badge');
    const bookingForm = document.querySelector('#booking-form');
    const baseFare = Number(bookingForm?.dataset.baseFare || 0);
    const fuelFare = Number(bookingForm?.dataset.fuelFare || 0);

    const updateFare = () => {
        const withFuel = fuelRadios.some((radio) => radio.checked && radio.value === '1');
        const fuel = withFuel ? fuelFare : 0;
        if (fuelAmount) fuelAmount.textContent = '₹' + fuel.toFixed(2);
        if (totalFare) totalFare.textContent = '₹' + (baseFare + fuel).toFixed(2);
        if (fuelBadge) fuelBadge.textContent = withFuel ? 'Added' : 'None';
    };
    fuelRadios.forEach((radio) => radio.addEventListener('change', updateFare));
    updateFare();

    const pickupTime = document.querySelector('#pickup-time');
    const returnTime = document.querySelector('#return-time');
    const toLocal = (date) => {
        const offset = date.getTimezoneOffset();
        return new Date(date.getTime() - offset * 60000).toISOString().slice(0, 16);
    };
    const minimum = toLocal(new Date());
    if (pickupTime) pickupTime.min = minimum;
    if (returnTime) {
        // The server may set a later min (the booking's pickup time).
        returnTime.min = returnTime.min && returnTime.min > minimum ? returnTime.min : minimum;
    }

    document.querySelectorAll('form[method="post"]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
            }
        });
    });

    const focusAddVehicle = document.querySelector('#focus-add-vehicle');
    if (focusAddVehicle) {
        focusAddVehicle.addEventListener('click', () => {
            document.querySelector('#form-add-fleet')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            document.querySelector('#fleet-make')?.focus({ preventScroll: true });
        });
    }
})();
