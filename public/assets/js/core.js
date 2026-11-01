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

    // Re-check auth state when the browser restores a page from its back/forward cache.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            window.location.reload();
        }
    });

    // Flash messages can be dismissed without reloading the page.
    document.querySelectorAll('.flash').forEach((flash) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'flash-close';
        button.setAttribute('aria-label', 'Dismiss message');
        button.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">close</span>';
        button.addEventListener('click', () => {
            const stack = flash.parentElement;
            flash.remove();
            if (stack?.classList.contains('flash-stack') && !stack.querySelector('.flash')) {
                stack.remove();
            }
        });
        flash.append(button);
    });
})();
