document.addEventListener('DOMContentLoaded', () => {

    const form = document.getElementById('studentRegisterForm');
    const submitBtn = document.getElementById('registerSubmitBtn');
    const toggleReg = document.getElementById('toggleRegPassword');
    const toggleConfirm = document.getElementById('toggleConfirmPassword');

    // Fill the Section dropdown with A through G.
    const SECTION_COUNT = 7;
    const sectionSelect = document.getElementById('section');
    if (sectionSelect) {
        for (let i = 0; i < SECTION_COUNT; i++) {
            const letter = String.fromCharCode(65 + i); // 'A' .. 'G'
            const opt = document.createElement('option');
            opt.value = letter;
            opt.textContent = `Section ${letter}`;
            sectionSelect.appendChild(opt);
        }
    }

    function setButtonState(isLoading) {
        if (!submitBtn) return;
        submitBtn.disabled = isLoading;
        submitBtn.innerHTML = isLoading
            ? '<i class="fa-solid fa-spinner fa-spin"></i> Syncing Record...'
            : '<i class="fa-solid fa-user-plus"></i> Complete Registration';
    }

    function toggleVisibility(inputId, toggleEl) {
        const input = document.getElementById(inputId);
        const icon = toggleEl.querySelector('i');
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        icon.classList.toggle('fa-eye', !isPassword);
        icon.classList.toggle('fa-eye-slash', isPassword);
    }

    if (toggleReg) toggleReg.addEventListener('click', () => toggleVisibility('registerPassword', toggleReg));
    if (toggleConfirm) toggleConfirm.addEventListener('click', () => toggleVisibility('confirmPassword', toggleConfirm));

    function readFileAsDataUrl(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(new Error('Unable to read the selected image.'));
            reader.readAsDataURL(file);
        });
    }

    const photoInput = document.getElementById('profilePhoto');
    if (photoInput) {
        photoInput.addEventListener('change', () => {
            const file = photoInput.files && photoInput.files[0];
            const wrapper = document.getElementById('profilePhotoPreviewWrapper');
            const preview = document.getElementById('profilePhotoPreview');
            if (!file || !wrapper || !preview) return;
            readFileAsDataUrl(file).then(dataUrl => {
                preview.src = dataUrl;
                wrapper.classList.remove('hidden');
            }).catch(() => { /* preview is a convenience only */ });
        });
    }

    ['studentId', 'lastName', 'firstName', 'middleName'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.textTransform = 'uppercase';
        el.addEventListener('input', () => {
            const pos = el.selectionStart;
            el.value = el.value.toUpperCase();
            el.setSelectionRange(pos, pos);
        });
    });

    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const studentId = document.getElementById('studentId').value.trim().toUpperCase();
        const lastName = document.getElementById('lastName').value.trim().toUpperCase();
        const firstName = document.getElementById('firstName').value.trim().toUpperCase();
        const middleName = document.getElementById('middleName').value.trim().toUpperCase();
        const fullName = `${lastName}, ${firstName}${middleName ? ' ' + middleName : ''}`;
        const courseProgram = document.getElementById('courseProgram').value;
        const yearLevel = document.getElementById('yearLevel').value;
        const section = document.getElementById('section').value;
        const studentEmail = document.getElementById('studentEmail').value.trim().toLowerCase();
        const password = document.getElementById('registerPassword').value;
        const confirmPass = document.getElementById('confirmPassword').value;
        const termsAccepted = document.getElementById('termsCheck').checked;

        if (!lastName || !firstName) {
            alert('Please enter at least your last name and first name.');
            return;
        }

        if (!termsAccepted) {
            alert('Please confirm the terms before registering.');
            return;
        }

        if (password !== confirmPass) {
            alert('Passwords do not match. Please check your fields.');
            return;
        }

        if (password.length < 8) {
            alert('Password must be at least 8 characters long.');
            return;
        }

        const photoFile = photoInput && photoInput.files && photoInput.files[0];
        if (!photoFile) {
            alert('Please upload a profile picture — it will be used on your QR ID card.');
            return;
        }

        setButtonState(true);

        try {
            // Real multipart upload (FormData) instead of reading the file into a
            // base64 string and JSON-encoding it — same pattern the profile photo
            // and excuse-letter uploads use.
            const formData = new FormData();
            formData.append('student_id', studentId);
            formData.append('full_name', fullName);
            formData.append('last_name', lastName);
            formData.append('first_name', firstName);
            formData.append('middle_name', middleName);
            formData.append('course_program', courseProgram);
            formData.append('year_level', yearLevel);
            formData.append('section', section);
            formData.append('institutional_email', studentEmail);
            formData.append('password', password);
            formData.append('confirm_password', confirmPass);
            formData.append('terms', termsAccepted ? '1' : '');
            formData.append('profile_picture', photoFile);

            const response = await fetch('../api/auth/register.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json'
                    // No Content-Type here — the browser sets the multipart
                    // boundary itself when the body is a FormData object.
                },
                body: formData
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to create your account.');
            }

            submitBtn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Account Created!';
            alert('Registration complete. Proceed to homepage');
            form.reset();
            window.location.href = 'index.html';
        } catch (err) {
            console.error('Registration failed:', err);
            alert('Registration Failed: ' + err.message);
            setButtonState(false);
        }
    });
});