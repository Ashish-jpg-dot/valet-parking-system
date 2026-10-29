(() => {
    // Native validation still works without JS; this only surfaces the message.
    document.querySelectorAll('form[method="post"]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
            }
        });
    });

    // Forms with data-confirm ask before submitting.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        const message = form instanceof HTMLFormElement ? form.dataset.confirm : '';
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
})();
