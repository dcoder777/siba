<script>
(function(){
    const layout = document.querySelector('.admin-layout');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const themeToggle = document.getElementById('themeToggle');
    const themeToggleIcon = document.getElementById('themeToggleIcon');
    const collapseKey = 'siba_erp_sidebar_collapsed';
    const themeKey = 'siba_erp_theme';
    const setTheme = (theme) => {
        const isDark = theme === 'dark';
        document.body.classList.toggle('theme-dark', isDark);
        if (themeToggleIcon) {
            themeToggleIcon.textContent = isDark ? 'L' : 'D';
        }
    };

    setTheme(localStorage.getItem(themeKey) === 'dark' ? 'dark' : 'light');
    if (layout && localStorage.getItem(collapseKey) === '1') {
        layout.classList.add('sidebar-collapsed');
    }
    sidebarToggle?.addEventListener('click', () => {
        layout?.classList.toggle('sidebar-collapsed');
        if (layout?.classList.contains('sidebar-collapsed')) {
            localStorage.setItem(collapseKey, '1');
        } else {
            localStorage.removeItem(collapseKey);
        }
    });
    themeToggle?.addEventListener('click', () => {
        const nextTheme = document.body.classList.contains('theme-dark') ? 'light' : 'dark';
        localStorage.setItem(themeKey, nextTheme);
        setTheme(nextTheme);
    });
})();
</script>
