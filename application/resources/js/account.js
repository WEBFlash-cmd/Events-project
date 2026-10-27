const fieldNames = {
    email: 'Email', name: 'Имя', password: 'Пароль',
    password_confirmation: 'Подтверждение пароля', current_password: 'Текущий пароль', code: 'Код',
};

function feedback(form, text, error = false) {
    const node = form.querySelector('.form-feedback');
    node.textContent = text;
    node.hidden = false;
    node.classList.toggle('is-error', error);
    node.focus();
}

function verificationStatus(user) {
    const banner = document.querySelector('#verification-status');
    if (!banner) return;
    banner.textContent = user.email_verified_at ? 'Email подтверждён.' : 'Email ещё не подтверждён. ';
    if (!user.email_verified_at) {
        const link = document.createElement('a');
        link.href = '/verify-email';
        link.className = 'inline-link';
        link.textContent = 'Ввести код из письма ↗';
        banner.append(link);
    }
}

for (const form of document.querySelectorAll('[data-account-form]')) {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (form.dataset.busy) return;
        const kind = form.dataset.accountForm;
        const values = Object.fromEntries(new FormData(form));
        delete values._token;
        if (values.email) values.email = values.email.trim().toLowerCase();
        if (values.code) values.code = values.code.trim();
        if (values.name !== undefined) values.name = values.name.trim();
        if (kind === 'profile') {
            if (!values.current_password) delete values.current_password;
            if (!values.name) delete values.name;
        }
        form.querySelector('.form-feedback').hidden = true;
        form.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
        if (values.password_confirmation !== undefined && values.password !== values.password_confirmation) {
            form.elements.password_confirmation.setAttribute('aria-invalid', 'true');
            feedback(form, 'Пароли не совпадают. Повторите пароль ещё раз.', true);
            return;
        }
        const button = form.querySelector('[type="submit"]');
        const label = button.textContent;
        button.disabled = true;
        button.textContent = 'Подождите…';
        form.dataset.busy = 'true';
        form.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(form.action, {
                method: form.dataset.method ?? 'POST', credentials: 'same-origin',
                headers: {
                    Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(values),
            });
            const payload = response.status === 204 ? {} : await response.json().catch(() => ({}));
            if (!response.ok) {
                if (response.status === 401) {
                    location.assign('/login');
                    return;
                }
                let message = 'Не удалось выполнить запрос. Попробуйте ещё раз.';
                if (response.status === 422) {
                    message = Object.entries(payload.errors ?? {}).map(([field, errors]) => {
                        form.elements.namedItem(field)?.setAttribute('aria-invalid', 'true');
                        return `${fieldNames[field] ?? 'Данные'}: ${errors.join(' ')}`;
                    }).join('\n') || 'Проверьте введённые данные.';
                } else if (response.status === 419) {
                    message = 'Сеанс истёк. Обновите страницу и повторите действие.';
                } else if (response.status === 429) {
                    message = 'Слишком много попыток. Подождите и попробуйте позже.';
                } else if (response.status === 403) {
                    message = 'Доступ запрещён. Возможно, ваш аккаунт заблокирован.';
                }
                feedback(form, message, true);
                return;
            }
            if (kind === 'login' || kind === 'register') {
                location.assign(payload.redirect);
            } else if (kind === 'logout') {
                location.assign('/');
            } else if (kind === 'password') {
                location.assign('/login?password_changed=1');
            } else if (kind === 'verify') {
                form.reset();
                feedback(form, 'Email подтверждён. Теперь можно перейти в личный кабинет.');
            } else if (kind === 'profile') {
                const changedEmail = form.elements.email.dataset.originalEmail !== payload.data.email;
                form.elements.email.value = payload.data.email;
                form.elements.email.dataset.originalEmail = payload.data.email;
                form.elements.current_password.value = '';
                verificationStatus(payload.data);
                feedback(form, changedEmail ? 'Данные сохранены. На новый email отправлен код подтверждения.' : 'Данные сохранены.');
            }
        } catch {
            feedback(form, 'Не удалось связаться с сервером. Проверьте соединение и повторите попытку.', true);
        } finally {
            delete form.dataset.busy;
            form.removeAttribute('aria-busy');
            button.disabled = false;
            button.textContent = label;
        }
    });
}

const login = document.querySelector('[data-account-form="login"]');
if (login && new URLSearchParams(location.search).get('password_changed') === '1') {
    feedback(login, 'Пароль изменён. Войдите с новым паролем.');
}

// Revalidate the page after restoring it from the browser's back/forward cache.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) location.reload();
});
