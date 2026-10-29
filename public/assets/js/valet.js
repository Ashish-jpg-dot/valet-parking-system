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
        window.scrollTo({ top: 0, behavior: 'smooth' });
        window.dispatchEvent(new CustomEvent('vf:tabchange', { detail: { name } }));
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => showTab(button.dataset.tab));
        button.addEventListener('keydown', (event) => {
            if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
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
})();
