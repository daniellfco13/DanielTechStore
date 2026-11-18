/**
 * Daniel Tech Store — estado de sessão (front-end)
 *
 * Estrutura:
 *   /index.html          (raiz)
 *   /HTML/*.html
 *   /JavaScript/authdaniel.js
 *   /*.php               (raiz)
 *
 * Detecta automaticamente se a página está na raiz ou em /HTML/
 * para montar os caminhos relativos corretos.
 */

const DTS_AUTH_KEY = 'dts_user';

// Se a URL contém /HTML/, sobe um nível; senão fica na raiz.
const DTS_ROOT = (function () {
  const path = window.location.pathname.replace(/\\/g, '/');
  if (path.includes('/HTML/') || /\/HTML$/i.test(path.replace(/\/[^/]*$/, ''))) {
    return '..';
  }
  return '.';
})();

function dtsUrl(rel) {
  // rel sem barra inicial, ex: 'perfildaniel.php' ou 'HTML/logindaniel.html'
  if (DTS_ROOT === '.') return rel;
  return DTS_ROOT + '/' + rel;
}

function dtsGetUser() {
  try {
    const raw = localStorage.getItem(DTS_AUTH_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch (e) {
    return null;
  }
}

function dtsSetUser(user) {
  try {
    localStorage.setItem(DTS_AUTH_KEY, JSON.stringify(user));
  } catch (e) { /* ignora */ }
}

async function dtsLogout() {
  try { await fetch(dtsUrl('logoutdaniel.php'), { method: 'POST' }); } catch (e) { /* segue */ }
  localStorage.removeItem(DTS_AUTH_KEY);
  window.location.href = dtsUrl('index.html');
}

async function dtsSyncSession() {
  try {
    const res = await fetch(dtsUrl('perfildaniel.php'), { cache: 'no-store' });
    if (res.status === 401) {
      localStorage.removeItem(DTS_AUTH_KEY);
      dtsInitAuthUI();
      return;
    }
    const data = await res.json();
    if (data.sucesso && data.usuario) {
      dtsSetUser(data.usuario);
      dtsInitAuthUI();
    }
  } catch (e) { /* mantém estado atual */ }
}

function dtsInitials(user) {
  const nome = (user.nome || '').trim();
  const sobrenome = (user.sobrenome || '').trim();
  if (nome) return (nome[0] + (sobrenome ? sobrenome[0] : '')).toUpperCase();
  if (user.email) return user.email[0].toUpperCase();
  return '?';
}

function dtsDisplayName(user) {
  const nome = [user.nome, user.sobrenome].filter(Boolean).join(' ').trim();
  return nome || user.email || 'Minha conta';
}

function dtsInitAuthUI() {
  const user = dtsGetUser();
  const authButtons = document.getElementById('authButtons');
  const userMenu = document.getElementById('userMenu');
  if (!userMenu) return;

  if (user) {
    if (authButtons) authButtons.style.display = 'none';
    userMenu.style.display = '';
    const initialsEl = document.getElementById('avatarInitials');
    const nameEl = document.getElementById('userDropdownName');
    const emailEl = document.getElementById('userDropdownEmail');
    if (initialsEl) initialsEl.textContent = dtsInitials(user);
    if (nameEl) nameEl.textContent = dtsDisplayName(user);
    if (emailEl) emailEl.textContent = user.email || '';
  } else {
    if (authButtons) authButtons.style.display = '';
    userMenu.style.display = 'none';
  }

  const avatarBtn = document.getElementById('avatarBtn');
  const dropdown = document.getElementById('userDropdown');
  if (avatarBtn && dropdown && !avatarBtn.dataset.dtsBound) {
    avatarBtn.dataset.dtsBound = '1';
    avatarBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      dropdown.classList.toggle('open');
    });
    document.addEventListener('click', (e) => {
      if (!userMenu.contains(e.target)) dropdown.classList.remove('open');
    });
  }

  const logoutBtn = document.getElementById('logoutBtn');
  if (logoutBtn && !logoutBtn.dataset.dtsBound) {
    logoutBtn.dataset.dtsBound = '1';
    logoutBtn.addEventListener('click', (e) => {
      e.preventDefault();
      dtsLogout();
    });
  }
}

document.addEventListener('DOMContentLoaded', () => { dtsInitAuthUI(); dtsSyncSession(); });
