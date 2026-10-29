
        (() => {
            const form = document.querySelector('#valet-reg-form');
            const fullName = document.querySelector('#fullName');
            const email = document.querySelector('#email');
            const phone = document.querySelector('#phone');
            const password = document.querySelector('#password');
            const confirmPassword = document.querySelector('#confirmPassword');
            const dlNumber = document.querySelector('#dlNumber');
            const documentInput = document.querySelector('#dlDocument');
            const dropzone = document.querySelector('#dropzone');
            const dropzoneEmpty = document.querySelector('#dropzone-empty');
            const dropzoneFilled = document.querySelector('#dropzone-filled');
            const formMessage = document.querySelector('#form-message');
            const removeFileButton = document.querySelector('#remove-file-btn');

            const fields = [
                [fullName, 'error-fullName'],
                [email, 'error-email'],
                [phone, 'error-phone'],
                [password, 'error-password'],
                [confirmPassword, 'error-confirmPassword'],
                [dlNumber, 'error-dlNumber']
            ];

            const setError = (id, message) => {
                const error = document.querySelector(`#error-${id}`);
                const input = id === 'dlDocument' ? documentInput : document.getElementById(id);
                if (!error || !input) return;
                error.textContent = message;
                error.classList.toggle('is-visible', Boolean(message));
                input.setAttribute('aria-invalid', String(Boolean(message)));
                if (id === 'dlDocument') {
                    dropzone.classList.toggle('has-error', Boolean(message));
                }
            };

            const clearFormMessage = () => {
                formMessage.hidden = true;
                formMessage.textContent = '';
            };

            const clearSelectedFile = () => {
                documentInput.value = '';
                dropzoneEmpty.hidden = false;
                dropzoneFilled.classList.remove('is-visible');
                document.querySelector('#file-name-text').textContent = '';
                document.querySelector('#file-size-text').textContent = '';
            };

            const togglePassword = (button, input) => {
                const reveal = input.type === 'password';
                input.type = reveal ? 'text' : 'password';
                button.setAttribute('aria-pressed', String(reveal));
                button.setAttribute('aria-label', reveal ? 'Hide password' : (input.id === 'password' ? 'Show password' : 'Show confirm password'));
            };

            document.querySelector('#toggle-pwd-btn').addEventListener('click', (event) => {
                togglePassword(event.currentTarget, password);
            });
            document.querySelector('#toggle-conf-btn').addEventListener('click', (event) => {
                togglePassword(event.currentTarget, confirmPassword);
            });

            const setSelectedFile = (file) => {
                if (!file) return;
                const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
                const extensionAllowed = /\.(pdf|jpe?g|png)$/i.test(file.name);
                if (!allowedTypes.includes(file.type) && !extensionAllowed) {
                    clearSelectedFile();
                    setError('dlDocument', 'Please select a PDF, JPG, or PNG file.');
                    return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    clearSelectedFile();
                    setError('dlDocument', 'The file must be 2 MB or smaller.');
                    return;
                }

                setError('dlDocument', '');
                document.querySelector('#file-name-text').textContent = file.name;
                const sizeMb = (file.size / (1024 * 1024)).toFixed(1);
                document.querySelector('#file-size-text').textContent = `${sizeMb} MB • Ready`;
                dropzoneEmpty.hidden = true;
                dropzoneFilled.classList.add('is-visible');
                clearFormMessage();
            };

            documentInput.addEventListener('change', () => {
                setSelectedFile(documentInput.files?.[0]);
            });

            ['dragenter', 'dragover'].forEach((eventName) => {
                dropzone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    dropzone.classList.add('is-dragging');
                });
            });

            ['dragleave', 'drop'].forEach((eventName) => {
                dropzone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    dropzone.classList.remove('is-dragging');
                });
            });

            dropzone.addEventListener('drop', (event) => {
                const file = event.dataTransfer?.files?.[0];
                if (!file) return;
                const transfer = new DataTransfer();
                transfer.items.add(file);
                documentInput.files = transfer.files;
                setSelectedFile(file);
            });

            removeFileButton.addEventListener('click', () => {
                clearSelectedFile();
                setError('dlDocument', '');
                clearFormMessage();
                documentInput.focus();
            });

            fields.forEach(([input, errorId]) => {
                input.addEventListener('input', () => {
                    const error = document.getElementById(errorId);
                    if (input === email && input.value.trim() && input.validity.valid) {
                        setError(input.id, '');
                    } else if (input === password && input.value.length >= 8) {
                        setError(input.id, '');
                    } else if (input === confirmPassword && input.value === password.value) {
                        setError(input.id, '');
                    } else if (![email, password, confirmPassword].includes(input) && input.value.trim()) {
                        setError(input.id, '');
                    }

                    if (input === password && confirmPassword.value && confirmPassword.value !== password.value) {
                        setError('confirmPassword', 'Passwords do not match.');
                    } else if (input === password && confirmPassword.value === password.value) {
                        setError('confirmPassword', '');
                    }
                    if (error?.classList.contains('is-visible') && input === confirmPassword && input.value !== password.value) {
                        setError('confirmPassword', 'Passwords do not match.');
                    }
                    clearFormMessage();
                });
            });

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                clearFormMessage();
                let firstInvalidField = null;
                const validation = [
                    [fullName, 'fullName', fullName.value.trim() ? '' : 'Please enter your full name.'],
                    [email, 'email', !email.value.trim() ? 'Email address is required.' : (!email.validity.valid ? 'Please provide a valid email format.' : '')],
                    [phone, 'phone', !phone.value.trim() ? 'Phone number is required.' : (!/^\d+$/.test(phone.value.replace(/[\s-]/g, '')) || phone.value.replace(/[\s-]/g, '').length < 10 ? 'Please enter a valid phone number (at least 10 digits).' : '')],
                    [password, 'password', !password.value ? 'Password is required.' : (password.value.length < 8 ? 'Password must be at least 8 characters.' : '')],
                    [confirmPassword, 'confirmPassword', !confirmPassword.value ? 'Please confirm your password.' : (confirmPassword.value !== password.value ? 'Passwords do not match.' : '')],
                    [dlNumber, 'dlNumber', dlNumber.value.trim() ? '' : 'Driving licence number is required.']
                ];

                validation.forEach(([input, id, message]) => {
                    setError(id, message);
                    if (message && !firstInvalidField) firstInvalidField = input;
                });

                const selectedFile = documentInput.files?.[0];
                let fileMessage = '';
                if (!selectedFile) {
                    fileMessage = 'Please upload a copy of your driving licence (PDF, JPG, PNG).';
                } else if (selectedFile.size > 2 * 1024 * 1024) {
                    fileMessage = 'The file must be 2 MB or smaller.';
                } else if (!['application/pdf', 'image/jpeg', 'image/png'].includes(selectedFile.type) && !/\.(pdf|jpe?g|png)$/i.test(selectedFile.name)) {
                    fileMessage = 'Please select a PDF, JPG, or PNG file.';
                }
                setError('dlDocument', fileMessage);
                if (fileMessage && !firstInvalidField) firstInvalidField = documentInput;

                if (firstInvalidField) {
                    firstInvalidField.focus();
                    return;
                }

                form.submit();
            });
        })();
    