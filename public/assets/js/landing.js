
        (() => {
            const menuButton = document.querySelector('.menu-toggle');
            const navigation = document.querySelector('#primary-navigation');
            const notice = document.querySelector('.notice');
            let noticeTimeout;

            const closeMenu = () => {
                if (!menuButton || !navigation) return;
                menuButton.setAttribute('aria-expanded', 'false');
                menuButton.setAttribute('aria-label', 'Open navigation');
                menuButton.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#icon-menu"></use></svg>';
                navigation.classList.remove('is-open');
            };

            menuButton?.addEventListener('click', () => {
                const isOpen = menuButton.getAttribute('aria-expanded') === 'true';
                menuButton.setAttribute('aria-expanded', String(!isOpen));
                menuButton.setAttribute('aria-label', isOpen ? 'Open navigation' : 'Close navigation');
                menuButton.innerHTML = `<svg class="icon" aria-hidden="true"><use href="#icon-${isOpen ? 'menu' : 'close'}"></use></svg>`;
                navigation?.classList.toggle('is-open', !isOpen);
            });

            navigation?.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', closeMenu);
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') closeMenu();
            });

            document.querySelectorAll('[data-notice]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (!notice) return;
                    notice.textContent = button.dataset.notice || '';
                    notice.hidden = false;
                    window.clearTimeout(noticeTimeout);
                    noticeTimeout = window.setTimeout(() => {
                        notice.hidden = true;
                    }, 4000);
                });
            });
        })();
    