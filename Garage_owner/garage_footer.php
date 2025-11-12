</main>
</div>

<script>
    function toggleSidebar() {
        const body = document.body;
        const isCollapsed = body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebarState', isCollapsed ? 'collapsed' : 'open');
    }

    window.onload = function() {
        // This is CRUCIAL for rendering the icons
        lucide.createIcons();
        const savedState = localStorage.getItem('sidebarState');
        if (savedState === 'collapsed') {
            document.body.classList.add('sidebar-collapsed');
        }
    };
</script>
</body>
</html>