document.addEventListener('DOMContentLoaded', () => {

    const page = document.getElementById('pageRoot');
    const regLink = document.getElementById('registerLink');
    const loginForm = document.getElementById('portalLoginForm');
    const submitBtn = document.getElementById('portalSubmitBtn');
    const passInput = document.getElementById('portalPassword');
    const togglePass = document.getElementById('togglePassword');

    function setButtonState(isLoading) {
        if (!submitBtn) return;
        submitBtn.disabled = isLoading;
        submitBtn.innerHTML = isLoading
            ? '<i class="fa-solid fa-spinner fa-spin"></i> Authenticating...'
            : 'Sign In to Portal';
    }

    if (togglePass && passInput) {
        togglePass.addEventListener('click', () => {
            const isPassword = passInput.getAttribute('type') === 'password';
            passInput.setAttribute('type', isPassword ? 'text' : 'password');
            const icon = togglePass.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye', !isPassword);
                icon.classList.toggle('fa-eye-slash', isPassword);
            }
        });
    }

    if (page) setTimeout(() => page.classList.remove('page-fade-in'), 500);

    if (regLink && page) {
        regLink.addEventListener('click', (e) => {
            e.preventDefault();
            page.classList.add('page-fade-out');
            setTimeout(() => {
                window.location.href = regLink.getAttribute('href');
            }, 380);
        });
    }

    // ── Forgot Password Modal ──────────────────────────────────────────
    const fpLink = document.getElementById('forgotPasswordLink');
    const fpModal = document.getElementById('forgotPasswordModal');
    const fpCloseBtn = document.getElementById('fpCloseBtn');
    const fpStepEmail = document.getElementById('fpStepEmail');
    const fpStepReset = document.getElementById('fpStepReset');
    const fpStepDone = document.getElementById('fpStepDone');
    const fpEmailForm = document.getElementById('fpEmailForm');
    const fpEmailInput = document.getElementById('fpEmail');
    const fpEmailSubmitBtn = document.getElementById('fpEmailSubmitBtn');
    const fpEmailError = document.getElementById('fpEmailError');
    const fpSentToEmail = document.getElementById('fpSentToEmail');
    const fpResetForm = document.getElementById('fpResetForm');
    const fpOtpInput = document.getElementById('fpOtp');
    const fpNewPasswordInput = document.getElementById('fpNewPassword');
    const fpConfirmPasswordInput = document.getElementById('fpConfirmPassword');
    const fpResetSubmitBtn = document.getElementById('fpResetSubmitBtn');
    const fpResetError = document.getElementById('fpResetError');
    const fpResendBtn = document.getElementById('fpResendBtn');
    const fpDoneBtn = document.getElementById('fpDoneBtn');

    let fpCurrentEmail = '';
    let fpResendCooldown = 0;
    let fpResendTimer = null;

    function fpShowStep(step) {
        [fpStepEmail, fpStepReset, fpStepDone].forEach(el => { if (el) el.style.display = 'none'; });
        if (step) step.style.display = 'block';
    }

    function fpOpenModal() {
        if (!fpModal) return;
        fpEmailError.style.display = 'none';
        fpResetError.style.display = 'none';
        if (fpEmailInput) fpEmailInput.value = document.getElementById('portalEmail').value || '';
        fpShowStep(fpStepEmail);
        fpModal.style.display = 'flex';
        fpModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function fpCloseModal() {
        if (!fpModal) return;
        fpModal.style.display = 'none';
        fpModal.setAttribute('aria-hidden', 'true');
        if (fpResetForm) fpResetForm.reset();
        if (fpResendTimer) { clearInterval(fpResendTimer); fpResendTimer = null; }
        document.body.style.overflow = '';
    }

    function fpSetEmailButtonState(isLoading) {
        if (!fpEmailSubmitBtn) return;
        fpEmailSubmitBtn.disabled = isLoading;
        fpEmailSubmitBtn.innerHTML = isLoading
            ? '<i class="fa-solid fa-spinner fa-spin"></i> Sending...'
            : 'Send Code';
    }

    function fpSetResetButtonState(isLoading) {
        if (!fpResetSubmitBtn) return;
        fpResetSubmitBtn.disabled = isLoading;
        fpResetSubmitBtn.innerHTML = isLoading
            ? '<i class="fa-solid fa-spinner fa-spin"></i> Resetting...'
            : 'Reset Password';
    }

    function fpStartResendCooldown(seconds) {
        if (!fpResendBtn) return;
        fpResendCooldown = seconds;
        fpResendBtn.disabled = true;
        fpResendBtn.textContent = `Resend available in ${fpResendCooldown}s`;
        if (fpResendTimer) clearInterval(fpResendTimer);
        fpResendTimer = setInterval(() => {
            fpResendCooldown -= 1;
            if (fpResendCooldown <= 0) {
                clearInterval(fpResendTimer);
                fpResendTimer = null;
                fpResendBtn.disabled = false;
                fpResendBtn.textContent = "Didn't get a code? Resend";
            } else {
                fpResendBtn.textContent = `Resend available in ${fpResendCooldown}s`;
            }
        }, 1000);
    }

    async function fpRequestOtp(email) {
        const response = await fetch('../api/auth/forgot_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ email })
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to send the reset code right now.');
        }
        return result;
    }

    if (fpLink) {
        fpLink.addEventListener('click', (e) => {
            e.preventDefault();
            fpOpenModal();
        });
    }

    if (fpCloseBtn) fpCloseBtn.addEventListener('click', fpCloseModal);
    if (fpModal) {
        fpModal.addEventListener('click', (e) => {
            if (e.target === fpModal) fpCloseModal();
        });
    }

    if (fpEmailForm) {
        fpEmailForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            fpEmailError.style.display = 'none';
            const email = fpEmailInput.value.trim().toLowerCase();
            if (!email) return;

            fpSetEmailButtonState(true);
            try {
                await fpRequestOtp(email);
                fpCurrentEmail = email;
                fpSentToEmail.textContent = email;
                fpShowStep(fpStepReset);
                fpStartResendCooldown(60);
            } catch (err) {
                fpEmailError.textContent = err.message || 'Unexpected error while sending the code.';
                fpEmailError.style.display = 'block';
            } finally {
                fpSetEmailButtonState(false);
            }
        });
    }

    if (fpResendBtn) {
        fpResendBtn.addEventListener('click', async () => {
            if (fpResendBtn.disabled || !fpCurrentEmail) return;
            fpResetError.style.display = 'none';
            fpResendBtn.disabled = true;
            fpResendBtn.textContent = 'Resending...';
            try {
                await fpRequestOtp(fpCurrentEmail);
                fpStartResendCooldown(60);
            } catch (err) {
                fpResetError.textContent = err.message || 'Unexpected error while resending the code.';
                fpResetError.style.display = 'block';
                fpResendBtn.disabled = false;
                fpResendBtn.textContent = "Didn't get a code? Resend";
            }
        });
    }

    if (fpResetForm) {
        fpResetForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            fpResetError.style.display = 'none';

            const otp = fpOtpInput.value.trim();
            const newPassword = fpNewPasswordInput.value;
            const confirmPassword = fpConfirmPasswordInput.value;

            if (!otp || !newPassword || !confirmPassword) {
                fpResetError.textContent = 'Please fill out every field.';
                fpResetError.style.display = 'block';
                return;
            }
            if (newPassword !== confirmPassword) {
                fpResetError.textContent = 'New passwords do not match.';
                fpResetError.style.display = 'block';
                return;
            }
            if (newPassword.length < 8) {
                fpResetError.textContent = 'New password must be at least 8 characters long.';
                fpResetError.style.display = 'block';
                return;
            }

            fpSetResetButtonState(true);
            try {
                const response = await fetch('../api/auth/reset_password.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        email: fpCurrentEmail,
                        otp,
                        new_password: newPassword,
                        confirm_password: confirmPassword
                    })
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to reset your password right now.');
                }
                fpShowStep(fpStepDone);
                if (fpResendTimer) { clearInterval(fpResendTimer); fpResendTimer = null; }
            } catch (err) {
                fpResetError.textContent = err.message || 'Unexpected error while resetting your password.';
                fpResetError.style.display = 'block';
            } finally {
                fpSetResetButtonState(false);
            }
        });
    }

    if (fpDoneBtn) {
        fpDoneBtn.addEventListener('click', () => {
            const portalEmailInput = document.getElementById('portalEmail');
            if (portalEmailInput) portalEmailInput.value = fpCurrentEmail;
            fpCloseModal();
        });
    }

    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const email = document.getElementById('portalEmail').value.trim().toLowerCase();
            const password = passInput ? passInput.value : '';

            if (!email || !password) {
                alert('Please fill out both your email and password.');
                return;
            }

            setButtonState(true);

            try {
                const response = await fetch('../api/auth/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ email, password })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to sign in right now.');
                }

                localStorage.setItem('cusg_current_user', JSON.stringify(result.user));
                submitBtn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Access Granted!';

                setTimeout(() => {
                    window.location.href = 'student.html';
                }, 800);
            } catch (err) {
                const message = err.message || 'Unexpected error while signing in.';
                if (message.toLowerCase().includes('user not found') || message.toLowerCase().includes('no user')) {
                    alert('No user found. Register now.');
                } else {
                    alert(message);
                }
                setButtonState(false);
            }
        });
    }
});