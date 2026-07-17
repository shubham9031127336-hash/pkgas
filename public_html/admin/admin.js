document.querySelector('.skip-link')?.addEventListener('click', function(e){e.preventDefault();var t=document.getElementById('main-content');if(t){t.setAttribute('tabindex','-1');t.focus();window.scrollTo(0,t.offsetTop);}});

function toggleNavDropdown(btn) {
    const menu = btn.nextElementSibling;
    const chevron = btn.querySelector('.dropdown-chevron');
    if (menu.style.display === 'none' || menu.style.display === '') {
        menu.style.display = 'block';
        menu.classList.add('menu-open');
        chevron.style.transform = 'rotate(180deg)';
        btn.setAttribute('aria-expanded', 'true');

    } else {
        menu.style.display = 'none';
        menu.classList.remove('menu-open');
        chevron.style.transform = 'rotate(0deg)';
        btn.setAttribute('aria-expanded', 'false');
    }
}

// Expand sidebar on dropdown-toggle click when collapsed
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebarMenu');
    if (!sidebar) return;
    sidebar.querySelectorAll('.dropdown-toggle').forEach(item => {
        item.addEventListener('click', () => {
            if (window.innerWidth > 1024 && sidebar.offsetWidth <= 100) {
                sidebar.classList.add('sidebar-expand-click');
            }
        });
    });
    sidebar.addEventListener('mouseleave', () => {
        if (window.innerWidth > 1024) {
            sidebar.classList.remove('sidebar-expand-click');
        }
    });
});

document.addEventListener('click', function (e) {
    var target = e.target.closest('[data-confirm]');
    if (!target) return;
    var msg = target.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
    if (!confirm(msg)) {
        e.preventDefault();
        e.stopPropagation();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebarMenu');
    
    const sidebarClose = document.getElementById('sidebarClose');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.toggle('active');
        });
        
        if (sidebarClose) {
            sidebarClose.addEventListener('click', () => {
                sidebar.classList.remove('active');
            });
        }
        
        sidebar.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth <= 1024 && sidebar.classList.contains('active')) {
                    setTimeout(() => sidebar.classList.remove('active'), 150);
                }
            });
        });
        
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1024 && sidebar.classList.contains('active')) {
                if (!sidebar.contains(e.target) && e.target !== menuToggle) {
                    sidebar.classList.remove('active');
                }
            }
        });
    }
});

function showToast(message, type) {
    type = type || 'info';
    var container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    var icons = { success: '\u2713', error: '\u2717', info: '\u2139' };
    toast.innerHTML = '<span>' + (icons[type] || '\u2139') + '</span><span>' + message + '</span><span class="toast-dismiss" onclick="this.parentElement.remove()">&times;</span>';
    container.appendChild(toast);
    setTimeout(function () {
        toast.style.animation = 'toastOut 0.3s ease-in forwards';
        setTimeout(function () { if (toast.parentElement) toast.remove(); }, 300);
    }, 4000);
}

document.addEventListener('DOMContentLoaded', function () {
    var errorFlash = document.getElementById('flashError');
    if (errorFlash) { showToast(errorFlash.getAttribute('data-message'), 'error'); errorFlash.remove(); }
    var successFlash = document.getElementById('flashSuccess');
    if (successFlash) { showToast(successFlash.getAttribute('data-message'), 'success'); successFlash.remove(); }
});
