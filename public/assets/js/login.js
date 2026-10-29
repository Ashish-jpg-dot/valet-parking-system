
        (() => {
            const form = document.querySelector('#login-form');
            const emailInput = document.querySelector('#email-input');
            const passwordInput = document.querySelector('#password-input');
            const emailError = document.querySelector('#email-error');
            const passwordError = document.querySelector('#password-error');
            const formMessage = document.querySelector('#form-message');
            const togglePassword = document.querySelector('#toggle-password');

            const setFieldError = (input, messageElement, message) => {
                input.setAttribute('aria-invalid', String(Boolean(message)));
                messageElement.textContent = message;
                messageElement.classList.toggle('is-visible', Boolean(message));
            };

            const clearFormMessage = () => {
                formMessage.hidden = true;
                formMessage.textContent = '';
                formMessage.classList.remove('error-message');
            };

            emailInput.addEventListener('input', () => {
                if (emailInput.value.trim() && emailInput.validity.valid) {
                    setFieldError(emailInput, emailError, '');
                }
                clearFormMessage();
            });

            passwordInput.addEventListener('input', () => {
                if (passwordInput.value.length > 0) {
                    setFieldError(passwordInput, passwordError, '');
                }
                clearFormMessage();
            });

            togglePassword.addEventListener('click', () => {
                const reveal = passwordInput.type === 'password';
                passwordInput.type = reveal ? 'text' : 'password';
                togglePassword.setAttribute('aria-pressed', String(reveal));
                togglePassword.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
            });

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                clearFormMessage();

                const email = emailInput.value.trim();
                emailInput.value = email;
                let firstInvalidField = null;

                if (!email) {
                    setFieldError(emailInput, emailError, 'Email address is required.');
                    firstInvalidField = emailInput;
                } else if (!emailInput.validity.valid) {
                    setFieldError(emailInput, emailError, 'Please enter a valid email address.');
                    firstInvalidField = emailInput;
                } else {
                    setFieldError(emailInput, emailError, '');
                }

                if (!passwordInput.value) {
                    setFieldError(passwordInput, passwordError, 'Password is required.');
                    firstInvalidField ||= passwordInput;
                } else {
                    setFieldError(passwordInput, passwordError, '');
                }

                if (firstInvalidField) {
                    firstInvalidField.focus();
                    return;
                }

                form.submit();
            });

            document.querySelectorAll('[data-unavailable]').forEach((button) => {
                button.addEventListener('click', () => {
                    clearFormMessage();
                    formMessage.textContent = button.dataset.unavailable;
                    formMessage.hidden = false;
                });
            });
        })();
    