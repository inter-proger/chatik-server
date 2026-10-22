<?php

// Страница админ-панели. Вся логика — в JS: он вызывает JSON-методы /admin/*
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>ServerAi — админка</title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; font: 15px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; color: #111827; }
    main { max-width: 640px; margin: 32px auto; padding: 0 16px; }
    h1 { font-size: 20px; margin: 0 0 16px; display: flex; justify-content: space-between; align-items: center; }
    .card { background: #fff; border-radius: 10px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .card h2 { font-size: 15px; margin: 0 0 12px; color: #374151; }
    label { display: block; margin-bottom: 10px; }
    input, textarea { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
    textarea { min-height: 110px; resize: vertical; }
    button { padding: 8px 16px; border: 0; border-radius: 6px; background: #2563eb; color: #fff; font: inherit; cursor: pointer; }
    button:disabled { opacity: .5; cursor: default; }
    button.link { background: none; color: #2563eb; padding: 4px 0; }
    button.secondary { background: #e5e7eb; color: #111827; }
    .actions { display: flex; gap: 8px; }
    .list { max-height: 280px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 8px; }
    .item { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; cursor: pointer; display: flex; justify-content: space-between; gap: 8px; }
    .item:last-child { border-bottom: 0; }
    .item:hover { background: #f9fafb; }
    .item.selected { background: #dbeafe; }
    .muted { color: #6b7280; font-size: 13px; }
    .empty { padding: 10px; color: #6b7280; }
    .notice { padding: 10px 12px; border-radius: 6px; margin-top: 12px; }
    .notice.ok { background: #dcfce7; color: #166534; }
    .notice.err { background: #fee2e2; color: #991b1b; }
    [hidden] { display: none !important; }
</style>
</head>
<body>
<main>
    <section id="login" class="card" hidden>
        <h2>Вход в админку</h2>
        <form id="loginForm">
            <label>Логин <input name="login" autocomplete="username" required></label>
            <label>Пароль <input name="password" type="password" autocomplete="current-password" required></label>
            <button type="submit">Войти</button>
            <div id="loginError" class="notice err" hidden></div>
        </form>
    </section>

    <div id="app" hidden>
        <h1>Действия от имени пользователя <button id="logout" class="link">Выйти</button></h1>

        <section class="card">
            <h2>1. Пользователь</h2>
            <input id="userQuery" placeholder="Поиск по имени или логину">
            <div id="users" class="list"></div>
            <div id="usersMore" class="muted" hidden>Показаны первые 50 — уточните поиск</div>
        </section>

        <section id="conversationsCard" class="card" hidden>
            <h2>2. Диалог</h2>
            <div id="conversations" class="list"></div>
            <button id="conversationsMore" class="link" hidden>Показать ещё</button>
        </section>

        <section id="sendCard" class="card" hidden>
            <h2>3. Сообщение</h2>
            <form id="sendForm">
                <div class="muted" id="sendSummary"></div>
                <label><textarea name="text" maxlength="4096" required></textarea></label>
                <div class="actions">
                    <button type="submit">Отправить</button>
                    <button type="button" id="markRead" class="secondary">Прочитать чат</button>
                </div>
            </form>
            <div id="sendNotice" class="notice" hidden></div>
        </section>
    </div>
</main>

<script>
const ERRORS = {
    1004: 'Невалидные параметры',
    1005: 'Текст пустой или длиннее 4096 символов',
    2002: 'Неверный логин или пароль',
    4001: 'Пользователь не найден',
    5002: 'Пользователь не состоит в этом диалоге',
    9001: 'Внутренняя ошибка сервера',
};

const $ = id => document.getElementById(id);

const state = { user: null, conversation: null, usersById: {}, offset: 0 };

class ApiError extends Error {
    constructor(code) { super(ERRORS[code] || 'Ошибка ' + code); this.code = code; }
}

async function api(method, params = {}, post = false) {
    const body = new URLSearchParams(params);
    const res = await fetch('/admin/' + method + (post ? '' : '?' + body), post ? { method: 'POST', body } : {});
    let json;
    try {
        json = await res.json();
    } catch (e) {
        throw new ApiError(9001);
    }
    if (json.status !== 1) {
        if (json.errorCode === 2003 && method !== 'login') {
            showLogin();
        }
        throw new ApiError(json.errorCode);
    }
    return json.response;
}

function showLogin() {
    $('app').hidden = true;
    $('login').hidden = false;
}

function showApp() {
    $('login').hidden = true;
    $('app').hidden = false;
    loadUsers();
}

function userLabel(user) {
    return user ? user.name + ' (@' + user.login + ')' : 'удалённый пользователь';
}

function item(title, subtitle, selected, onClick) {
    const el = document.createElement('div');
    el.className = 'item' + (selected ? ' selected' : '');
    const t = document.createElement('span');
    t.textContent = title;
    const s = document.createElement('span');
    s.className = 'muted';
    s.textContent = subtitle;
    el.append(t, s);
    el.addEventListener('click', onClick);
    return el;
}

function empty(text) {
    const el = document.createElement('div');
    el.className = 'empty';
    el.textContent = text;
    return el;
}

// ---- Пользователи ----

let usersRequest = 0;

async function loadUsers() {
    const id = ++usersRequest;
    try {
        const res = await api('getUsers', { query: $('userQuery').value });
        if (id !== usersRequest) return; // пришёл ответ на устаревший запрос
        const list = $('users');
        list.replaceChildren(...res.users.map(u => {
            const el = item(userLabel(u), 'id ' + u.userId, state.user && state.user.userId === u.userId, () => selectUser(u));
            el.dataset.userId = u.userId;
            return el;
        }));
        if (!res.users.length) list.append(empty('Никого не нашлось'));
        $('usersMore').hidden = !res.hasNext;
    } catch (e) {
        if (e.code !== 2003) $('users').replaceChildren(empty(e.message));
    }
}

let queryTimer;
$('userQuery').addEventListener('input', () => {
    clearTimeout(queryTimer);
    queryTimer = setTimeout(loadUsers, 250);
});

function selectUser(user) {
    state.user = user;
    state.conversation = null;
    state.usersById = {};
    state.offset = 0;
    for (const el of $('users').children) {
        el.classList.toggle('selected', el.dataset.userId === String(user.userId));
    }
    $('conversations').replaceChildren();
    $('conversationsCard').hidden = false;
    $('sendCard').hidden = true;
    loadConversations();
}

// ---- Диалоги ----

function conversationLabel(c) {
    if (c.type === 'single') {
        return userLabel(state.usersById[c.opponentUserId]);
    }
    return c.name || 'Группа без названия';
}

async function loadConversations() {
    const user = state.user;
    $('conversationsMore').hidden = true;
    try {
        const res = await api('getConversations', { userId: user.userId, offset: state.offset });
        if (user !== state.user) return;
        for (const u of res.users) state.usersById[u.userId] = u;
        state.offset += res.conversations.length;

        const list = $('conversations');
        for (const c of res.conversations) {
            const subtitle = (c.type === 'single' ? 'личный' : 'группа') + ' · id ' + c.conversationId;
            const el = item(conversationLabel(c), subtitle, false, () => selectConversation(c, el));
            list.append(el);
        }
        if (!list.children.length) list.append(empty('У пользователя нет диалогов'));
        $('conversationsMore').hidden = !res.hasNext;
    } catch (e) {
        if (e.code !== 2003) $('conversations').replaceChildren(empty(e.message));
    }
}

$('conversationsMore').addEventListener('click', loadConversations);

function selectConversation(c, el) {
    state.conversation = c;
    for (const other of $('conversations').children) other.classList.remove('selected');
    el.classList.add('selected');
    $('sendSummary').textContent = 'От: ' + userLabel(state.user) + ' → ' + conversationLabel(c);
    $('sendNotice').hidden = true;
    $('sendCard').hidden = false;
    $('sendForm').text.focus();
}

// ---- Отправка ----

function notice(el, ok, text) {
    el.className = 'notice ' + (ok ? 'ok' : 'err');
    el.textContent = text;
    el.hidden = false;
}

$('sendForm').addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;
    const button = form.querySelector('button');
    button.disabled = true;
    try {
        const res = await api('sendMessage', {
            userId: state.user.userId,
            conversationId: state.conversation.conversationId,
            text: form.text.value,
        }, true);
        form.text.value = '';
        notice($('sendNotice'), true, 'Сообщение отправлено (id ' + res.message.messageId + ')');
    } catch (err) {
        if (err.code !== 2003) notice($('sendNotice'), false, err.message);
    } finally {
        button.disabled = false;
    }
});

// ---- Прочтение ----

$('markRead').addEventListener('click', async e => {
    const button = e.target;
    button.disabled = true;
    try {
        const res = await api('markRead', {
            userId: state.user.userId,
            conversationId: state.conversation.conversationId,
        }, true);
        const readInfo = res.readInfo;
        notice($('sendNotice'), true, readInfo.lastReadMessageId
            ? 'Чат прочитан до сообщения id ' + readInfo.lastReadMessageId
            : 'В чате нет сообщений');
    } catch (err) {
        if (err.code !== 2003) notice($('sendNotice'), false, err.message);
    } finally {
        button.disabled = false;
    }
});

// ---- Вход / выход ----

$('loginForm').addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;
    try {
        await api('login', { login: form.login.value, password: form.password.value }, true);
        form.reset();
        $('loginError').hidden = true;
        showApp();
    } catch (err) {
        notice($('loginError'), false, err.message);
    }
});

$('logout').addEventListener('click', async () => {
    try { await api('logout', {}, true); } catch (e) {}
    showLogin();
});

// Если сессии админа нет, api() на первом же запросе покажет форму входа
showApp();
</script>
</body>
</html>
