
        (() => {
            const form = document.querySelector('#register-form');
            const fullName = document.querySelector('#reg-fullname');
            const email = document.querySelector('#reg-email');
            const phone = document.querySelector('#reg-phone');
            const password = document.querySelector('#reg-password');
            const confirmPassword = document.querySelector('#reg-confirm-password');
            const formMessage = document.querySelector('#form-message');
            const fields = [
                [fullName, document.querySelector('#fullname-error'), 'Full name is required.'],
                [email, document.querySelector('#email-error'), 'Email address is required.'],
                [phone, document.querySelector('#phone-error'), 'Phone number is required.'],
                [password, document.querySelector('#password-error'), 'Password is required.'],
                [confirmPassword, document.querySelector('#confirm-password-error'), 'Please confirm your password.']
            ];

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

            document.querySelectorAll('[data-password-toggle]').forEach((button) => {
                button.addEventListener('click', () => {
                    const input = document.getElementById(button.dataset.passwordToggle);
                    if (!input) return;
                    const reveal = input.type === 'password';
                    input.type = reveal ? 'text' : 'password';
                    button.setAttribute('aria-pressed', String(reveal));
                    button.setAttribute('aria-label', reveal ? 'Hide password' : (input.id === 'reg-password' ? 'Show password' : 'Show confirm password'));
                });
            });

            fields.forEach(([input, error]) => {
                input.addEventListener('input', () => {
                    if (input === email && input.value.trim() && input.validity.valid) {
                        setFieldError(input, error, '');
                    } else if (input === password && input.value.length >= 8) {
                        setFieldError(input, error, '');
                    } else if (input === confirmPassword && input.value === password.value) {
                        setFieldError(input, error, '');
                    } else if (input !== email && input !== password && input !== confirmPassword && input.value.trim()) {
                        setFieldError(input, error, '');
                    }

                    if (input === password && confirmPassword.value && confirmPassword.value !== password.value) {
                        setFieldError(confirmPassword, document.querySelector('#confirm-password-error'), 'Passwords do not match.');
                    } else if (input === password && confirmPassword.value === password.value) {
                        setFieldError(confirmPassword, document.querySelector('#confirm-password-error'), '');
                    }
                    clearFormMessage();
                });
            });

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                clearFormMessage();
                let firstInvalidField = null;

                fields.forEach(([input, error, requiredMessage]) => {
                    const value = input.value.trim();
                    let message = '';

                    if (!value) {
                        message = requiredMessage;
                    } else if (input === email && !input.validity.valid) {
                        message = 'Please enter a valid email address.';
                    } else if (input === password && input.value.length < 8) {
                        message = 'Password must be at least 8 characters.';
                    } else if (input === confirmPassword && input.value !== password.value) {
                        message = 'Passwords do not match.';
                    }

                    setFieldError(input, error, message);
                    if (message && !firstInvalidField) firstInvalidField = input;
                });

                if (firstInvalidField) {
                    firstInvalidField.focus();
                    return;
                }

                form.submit();
            });
        })();
    